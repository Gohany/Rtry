<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\CircuitOpenException;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Budget\InMemoryRetryBudgetStore;
use Gohany\Rtry\Impl\Budget\TokenBucketRetryBudget;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicy;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\TestCase;

/**
 * End to end: parse a real rtry: string, run Retry::try() against a failing operation, and
 * record the sleeps it actually took. Each test names the bug from the 2026-11-17 review it
 * pins down (chunk chk_4b7c55f4bbaf746f, bugs 1-11).
 */
final class SpecToSleepsTest extends TestCase
{
    private const SEED = 1117;

    private FrozenClock $clock;
    private RecordingSleeper $sleeper;
    private Retry $retry;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->retry = new Retry($this->clock, $this->sleeper, null, SeededRandom::randomizer(self::SEED));
    }

    /**
     * An operation that throws $error on its first $failures attempts, then returns 'ok'.
     *
     * @param list<int> $seen attempt numbers, filled in as it runs
     */
    private static function failing(int $failures, \Throwable|\Closure $error, ?array &$seen = null): \Closure
    {
        $seen = [];
        return static function (AttemptContextInterface $attempt) use ($failures, $error, &$seen): string {
            $seen[] = $attempt->attemptNumber();
            if ($attempt->attemptNumber() <= $failures) {
                throw $error instanceof \Closure ? $error($attempt) : $error;
            }
            return 'ok';
        };
    }

    private static function http(int $status, array $headers = []): \Closure
    {
        return static fn () => HttpFailure::status($status, $headers);
    }

    /** Bugs 1, 3, 5, 6, 9: the README quick-start spec, through four 503s. */
    public function testReadmeSpecSleepsAJitteredExponentialSchedule(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=5;d=200;m=exp;b=2;cap=8s;j=100%@full;on=5xx,ETIMEDOUT');

        $value = $this->retry->try(self::failing(4, self::http(503), $seen), $policy);

        self::assertSame('ok', $value, 'try() returns the operation\'s value');
        self::assertSame([1, 2, 3, 4, 5], $seen);
        self::assertSame(
            SeededRandom::expectedDraws(self::SEED, [[0, 200], [0, 400], [0, 800], [0, 1600]]),
            $this->sleeper->sleeps()
        );
    }

    /** Bugs 3 and 5: no cap and no j still back off, with full jitter. */
    public function testNoCapAndNoJitterKeyMeansFullJitterUncapped(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100');

        $this->retry->try(self::failing(3, self::http(500)), $policy);

        self::assertSame(SeededRandom::expectedDraws(self::SEED, [[0, 100], [0, 200], [0, 400]]), $this->sleeper->sleeps());
    }

    /** Bug 5: full jitter with a window draws below the delay, not in [0, window]. */
    public function testFullJitterWindowIsBelowTheDelay(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=8s;j=100@full');

        $this->retry->try(self::failing(2, self::http(503)), $policy);

        self::assertSame(SeededRandom::expectedDraws(self::SEED, [[7900, 8000], [15900, 16000]]), $this->sleeper->sleeps());
    }

    /** Bug 2: linear mode steps by d. */
    public function testLinearStepsByD(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;m=lin;d=100;j=0');

        $this->retry->try(self::failing(3, self::http(503)), $policy);

        self::assertSame([100, 200, 300], $this->sleeper->sleeps());
    }

    /** Sequence mode, the first delay being the one before attempt 2. */
    public function testSequenceStartsBeforeAttemptTwo(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=5;m=seq;seq=50,100,1s*;j=0');

        $this->retry->try(self::failing(4, self::http(503)), $policy);

        self::assertSame([50, 100, 1000, 1000], $this->sleeper->sleeps());
    }

    /** Bugs 1 and 10: sa waits once before attempt 1, unjittered, and does not scale the backoff. */
    public function testStartAfterIsOneUnjitteredWaitAndNotTheBackoffBase(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;sa=50;j=0');

        $this->retry->try(self::failing(2, self::http(503)), $policy);

        self::assertSame([50, 100, 200], $this->sleeper->sleeps());
    }

    /** Bug 4: the 1.x README spelled the mode key mode=; it is m=. */
    public function testTheOldModeKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RtryPolicyFactory())->fromSpec('rtry:a=5;d=200;mode=exp');
    }

    /** Bug 6: on= is honoured, so a 422 runs once and its error comes back. */
    public function testA422RunsOnceUnderOn(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=5;d=200;on=5xx,ETIMEDOUT');
        $error = HttpFailure::status(422);

        try {
            $this->retry->try(self::failing(99, $error, $seen), $policy);
            self::fail('a 422 must be rethrown');
        } catch (HttpFailure $e) {
            self::assertSame($error, $e);
        }
        self::assertSame([1], $seen);
        self::assertSame([], $this->sleeper->sleeps());
    }

    /** Bug 6 and SPEC 6.3: without on=, the default tokens apply; a 422 and an untagged error run once. */
    public function testWithoutOnOnlyTheDefaultTokensRetry(): void
    {
        $factory = new RtryPolicyFactory();

        $result = $this->retry->tryOutcome(self::failing(99, self::http(422)), $factory->fromSpec('rtry:a=5;d=100'));
        self::assertSame([1, GiveUpReason::NotRetryable], [$result->attempts(), $result->giveUpReason()]);

        $result = $this->retry->tryOutcome(self::failing(99, new \LogicException('a bug')), $factory->fromSpec('rtry:a=5;d=100'));
        self::assertSame([1, GiveUpReason::NotRetryable], [$result->attempts(), $result->giveUpReason()]);

        $result = $this->retry->tryOutcome(self::failing(99, new \RuntimeException('Connection timed out')), $factory->fromSpec('rtry:a=3;d=100'));
        self::assertSame([3, GiveUpReason::AttemptsExhausted], [$result->attempts(), $result->giveUpReason()]);
    }

    /** Bug 7: a string spec follows Retry-After, above the cap; jitter only adds above it. */
    public function testRetryAfterSecondsIsWaitedOut(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=2;d=100;cap=1s');

        $value = $this->retry->try(self::failing(1, self::http(429, ['Retry-After' => '2'])), $policy);

        self::assertSame('ok', $value);
        self::assertCount(1, $this->sleeper->sleeps());
        self::assertGreaterThanOrEqual(2000, $this->sleeper->sleeps()[0]);
        self::assertLessThanOrEqual(2100, $this->sleeper->sleeps()[0]);
    }

    public function testRetryAfterHttpDateAndRateLimitResetAreWaitedOut(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');
        $inThreeSeconds = gmdate('D, d M Y H:i:s', intdiv($this->clock->nowUnixMs(), 1000) + 3) . ' GMT';

        $errors = [
            1 => HttpFailure::status(503, ['Retry-After' => $inThreeSeconds]),
            2 => HttpFailure::status(429, ['RateLimit-Reset' => '5']),
        ];
        $this->retry->try(self::failing(2, static fn (AttemptContextInterface $a) => $errors[$a->attemptNumber()]), $policy);

        self::assertSame([3000, 5000], $this->sleeper->sleeps());
    }

    public function testFollowHeadersOffIgnoresRetryAfter(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=2;d=100;j=0;fh=0');

        $this->retry->try(self::failing(1, self::http(429, ['Retry-After' => '2'])), $policy);

        self::assertSame([100], $this->sleeper->sleeps());
    }

    /** Bug 9: give-up rethrows the last attempt's error; tryOutcome() says why. */
    public function testGiveUpRethrowsTheLastErrorAndTryOutcomeSaysWhy(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10;j=0');
        $op = static fn (AttemptContextInterface $a) => throw new \RuntimeException('connection timed out on attempt ' . $a->attemptNumber());

        try {
            $this->retry->try($op, $policy);
            self::fail('try() must rethrow on give-up');
        } catch (\RuntimeException $e) {
            self::assertSame('connection timed out on attempt 3', $e->getMessage());
        }

        $result = $this->retry->tryOutcome($op, $policy);
        self::assertFalse($result->isSuccess());
        self::assertSame(GiveUpReason::AttemptsExhausted, $result->giveUpReason());
        self::assertSame('connection timed out on attempt 3', $result->lastError()?->getMessage());
    }

    /** Deadline: never sleeps past dl. */
    public function testDeadlineStopsBeforeSleepingPastIt(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=10;d=100;j=0;dl=350');

        $result = $this->retry->tryOutcome(self::failing(99, self::http(503)), $policy);

        self::assertSame([100, 200], $this->sleeper->sleeps());
        self::assertSame([3, GiveUpReason::DeadlineExceeded], [$result->attempts(), $result->giveUpReason()]);
    }

    /** Bug 10: a policy is immutable data, so reusing one gives the same schedule. */
    public function testAPolicyIsImmutableAndReusable(): void
    {
        foreach ((new \ReflectionClass(RtryPolicy::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringStartsNotWith('set', $method->getName());
            self::assertStringStartsNotWith('reset', $method->getName());
        }

        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;sa=20');
        self::assertSame(20, $policy->startAfterMs());
        self::assertSame(20, $policy->startAfterMs());

        $first = new RecordingSleeper();
        $second = new RecordingSleeper();
        (new Retry(new FrozenClock(), $first, null, SeededRandom::randomizer(7)))->try(self::failing(3, self::http(503)), $policy);
        (new Retry(new FrozenClock(), $second, null, SeededRandom::randomizer(7)))->try(self::failing(3, self::http(503)), $policy);
        self::assertSame($first->sleeps(), $second->sleeps());
    }

    /** Bug 11: elapsed time and the deadline come from the one injected clock. */
    public function testElapsedTimeComesFromTheInjectedClock(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;dl=10s');
        $seen = [];

        $this->retry->try(static function (AttemptContextInterface $a) use (&$seen): string {
            $seen[] = [$a->attemptNumber(), $a->scheduledDelayMs(), $a->elapsedSinceFirstMs(), $a->remainingBudgetMs()];
            if ($a->attemptNumber() < 3) {
                throw HttpFailure::status(503);
            }
            return 'ok';
        }, $policy);

        self::assertSame([[1, 0, 0, 10000], [2, 100, 100, 9900], [3, 200, 300, 9700]], $seen);
    }

    /** Bug 8: h and t are hints; attempts still run one at a time. */
    public function testHedgeAndTimeoutAreHintsOnly(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;t=50;h=3@10');

        $this->retry->try(self::failing(2, self::http(503), $seen), $policy);

        self::assertSame([1, 2, 3], $seen);
        self::assertSame([100, 200], $this->sleeper->sleeps());
        self::assertSame(3, $policy->hedge()?->lanes());
        self::assertSame(50, $policy->attemptTimeoutMs());
    }

    public function testABudgetCapsRetriesPerKey(): void
    {
        $budget = new TokenBucketRetryBudget(new InMemoryRetryBudgetStore($this->clock), 4, 0.1);
        $retry = $this->retry->withGuard('payments-api', $budget);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=10;d=100;j=0');

        // 4 tokens: the first retry leaves 3 (above 2), the second leaves 2 (not above 2).
        $result = $retry->tryOutcome(self::failing(99, self::http(503)), $policy);

        self::assertSame([2, GiveUpReason::BudgetExhausted], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame([100], $this->sleeper->sleeps());
    }

    public function testAnOpenBreakerFailsFastWithoutRunningTheOperation(): void
    {
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, 2, 10_000, 5_000);
        $retry = $this->retry->withGuard('search-api', null, $breaker);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=5;d=100;j=0');

        $first = $retry->tryOutcome(self::failing(99, self::http(503)), $policy);
        self::assertSame([2, GiveUpReason::CircuitOpen], [$first->attempts(), $first->giveUpReason()]);

        $seen = [];
        try {
            $retry->try(self::failing(0, self::http(503), $seen), $policy);
            self::fail('an open circuit must throw');
        } catch (CircuitOpenException $e) {
            self::assertSame('search-api', $e->key());
        }
        self::assertSame([], $seen);
    }
}
