<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\ErrorResponseLocator;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptContext;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Impl\Rules\InstanceOfRule;
use Gohany\Rtry\Tests\Support\FakeResponse;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\LaravelShapedFailure;
use PHPUnit\Framework\TestCase;

/**
 * Edges of 2.3.0 that were right but unpinned: each test here fails under one of the mutations
 * the re-verification of Rtry PR #6 found surviving (chunk chk_eeca7a4f1fa98461), and the
 * scheduler as the one source of truth for its ceiling on the durable path.
 */
final class PinnedEdgesTest extends TestCase
{
    private const DAY_MS = 86_400_000;
    private const HOUR_MS = 3_600_000;
    private const WEEK_MS = 7 * self::DAY_MS;

    /** @var list<int> */
    private array $sleeps = [];

    /** @return array{int, ?GiveUpReason} */
    private function attempt(string $spec, \Throwable $error): array
    {
        $clock = new FrozenClock();
        $sleeper = new RecordingSleeper($clock);
        $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer(1118));

        $result = $retry->tryOutcome(static fn () => throw $error, (new RtryPolicyFactory())->fromSpec($spec));

        $this->sleeps = $sleeper->sleeps();
        self::assertSame($error, $result->lastError(), 'the caller always gets its own error back');
        return [$result->attempts(), $result->giveUpReason()];
    }

    /** A Laravel-shaped error whose response's headers() throws. */
    private static function laravelWithThrowingHeaders(int $status): LaravelShapedFailure
    {
        $response = new class ($status) {
            public function __construct(private int $status)
            {
            }

            public function status(): int
            {
                return $this->status;
            }

            /** @return array<string, list<string>> */
            public function headers(): array
            {
                throw new \RuntimeException('headers unavailable');
            }
        };
        return new LaravelShapedFailure($response, $status, '');
    }

    // ---- ErrorResponseLocator --------------------------------------------------------------

    public function testAThrowingHeadersOnALaravelResponseCostsOnlyTheHeaders(): void
    {
        $error = self::laravelWithThrowingHeaders(503);

        $response = (new ErrorResponseLocator())->read($error);
        self::assertNotNull($response);
        self::assertSame([503, []], [$response->statusCode, $response->headers]);

        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=4;d=100;j=0', $error));
        self::assertSame([100, 200, 400], $this->sleeps);
    }

    public function testALaravelResponseStatusBelow100IsNoResponse(): void
    {
        // Status 99, and a body that names a timeout: with no response the message is read.
        $error = LaravelShapedFailure::status(99, [], 'upstream connection timed out');

        self::assertNull((new ErrorResponseLocator())->read($error));
        $meta = RuleBasedFailureClassifier::default()->classify($error);
        self::assertNull($meta->getStatusCode());
        self::assertContains('ETIMEDOUT', $meta->getTags());
    }

    public function testGetResponseIsReadBeforeAResponseProperty(): void
    {
        $property = new class {
            public function status(): int
            {
                return 404;
            }

            /** @return array<string, list<string>> */
            public function headers(): array
            {
                return [];
            }
        };
        $error = new class ($property) extends \RuntimeException {
            public function __construct(public object $response)
            {
                parent::__construct('both shapes');
            }

            public function getResponse(): FakeResponse
            {
                return new FakeResponse(503, ['Retry-After' => '2']);
            }
        };

        $response = (new ErrorResponseLocator())->read($error);
        self::assertNotNull($response);
        self::assertSame(503, $response->statusCode);
        self::assertSame(503, RuleBasedFailureClassifier::default()->classify($error)->getStatusCode());
    }

    // ---- RateLimitBackoffRule --------------------------------------------------------------

    public function testA408Or599WithRetryAfterIsRateLimited(): void
    {
        foreach ([408, 599] as $status) {
            $meta = RuleBasedFailureClassifier::default()->classify(HttpFailure::status($status, ['Retry-After' => '2']));
            self::assertContains('RATE_LIMITED', $meta->getTags(), (string) $status);
        }
    }

    public function testAStatusAbove599WithRetryAfterIsNotRateLimited(): void
    {
        foreach ([600, 999] as $status) {
            $meta = RuleBasedFailureClassifier::default()->classify(HttpFailure::status($status, ['Retry-After' => '2']));
            self::assertNotContains('RATE_LIMITED', $meta->getTags(), (string) $status);
        }
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt('rtry:a=4;d=100;j=0;on=default', HttpFailure::status(600, ['Retry-After' => '2'])));
    }

    public function testA403WithOneRequestLeftIsNotAWait(): void
    {
        $error = HttpFailure::status(403, ['X-RateLimit-Remaining' => '1', 'X-RateLimit-Reset' => '1800000030']);

        $meta = RuleBasedFailureClassifier::default()->classify($error);
        self::assertSame([[], null], [$meta->getTags(), $meta->getNotBeforeUnixMs()]);
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt('rtry:a=4;d=100;j=0;on=default', $error));
    }

    public function testANonNumericRemainingIsNotAWait(): void
    {
        foreach (['abc', 'none', ''] as $remaining) {
            $error = HttpFailure::status(403, ['RateLimit-Remaining' => $remaining, 'RateLimit-Reset' => '30']);
            $meta = RuleBasedFailureClassifier::default()->classify($error);
            self::assertSame([[], null], [$meta->getTags(), $meta->getNotBeforeUnixMs()], var_export($remaining, true));
        }
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(
            'rtry:a=4;d=100;j=0;on=default',
            HttpFailure::status(403, ['X-RateLimit-Remaining' => 'abc', 'X-RateLimit-Reset' => '1800000030']),
        ));
    }

    // ---- the scheduler's ceiling on the durable path ---------------------------------------

    public function testAWeekLongCeilingSpreadsAWaitAboveADayWhenSetOnTheSchedulerAlone(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100');
        $clock = new FrozenClock();
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, null);
        $error = HttpFailure::status(503, ['Retry-After' => '172800']);

        $delays = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $scheduler = new RtryScheduler($clock, SeededRandom::randomizer($seed), self::WEEK_MS);
            $decision = $scheduler->next($policy, $scheduler->outcome($error, $policy, $context), $context);
            self::assertTrue($decision->retry(), "seed $seed gave up: " . $decision->giveUpReason()?->value);
            $delays[] = $decision->delayMs();
        }
        self::assertGreaterThanOrEqual(172_800_000, min($delays));
        self::assertLessThanOrEqual(172_800_100, max($delays));
        self::assertGreaterThan(10, count(array_unique($delays)), 'spread, not all due in one millisecond');
    }

    public function testAnHourLongCeilingHonoursAnHourWhenSetOnTheSchedulerAlone(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100');
        $clock = new FrozenClock();
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, null);
        $error = HttpFailure::status(503, ['Retry-After' => '3600']);

        for ($seed = 1; $seed <= 50; $seed++) {
            $scheduler = new RtryScheduler($clock, SeededRandom::randomizer($seed), self::HOUR_MS);
            $decision = $scheduler->next($policy, $scheduler->outcome($error, $policy, $context), $context);
            self::assertSame(self::HOUR_MS, $decision->delayMs(), "seed $seed: " . $decision->giveUpReason()?->value);
        }

        $over = HttpFailure::status(503, ['Retry-After' => '3601']);
        $scheduler = new RtryScheduler($clock, null, self::HOUR_MS);
        self::assertSame(GiveUpReason::DeadlineExceeded, $scheduler->next($policy, $scheduler->outcome($over, $policy, $context), $context)->giveUpReason());
    }

    public function testFromErrorStillTakesTheCeilingAsAnArgument(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100');
        $clock = new FrozenClock();
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, null);
        $scheduler = new RtryScheduler($clock, null, self::HOUR_MS);
        $hour = HttpFailure::status(503, ['Retry-After' => '3600']);
        $twoDays = HttpFailure::status(503, ['Retry-After' => '172800']);

        $weekDelays = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $outcome = RtryAttemptOutcome::fromError($hour, null, $clock, $policy, 1, SeededRandom::randomizer($seed), $context, self::HOUR_MS);
            self::assertSame(self::HOUR_MS, $scheduler->next($policy, $outcome, $context)->delayMs(), "seed $seed");

            $outcome = RtryAttemptOutcome::fromError($twoDays, null, $clock, $policy, 1, SeededRandom::randomizer($seed), $context, self::WEEK_MS);
            $weekDelays[] = (int) $outcome->notBeforeUnixMs() - $clock->nowUnixMs();
        }
        self::assertGreaterThan(10, count(array_unique($weekDelays)), 'a week-long ceiling leaves room to spread');
    }

    public function testTheSchedulersOutcomeSpreadsByTheContextsAttempt(): void
    {
        // Attempt 3 failed: the next backoff is d * 2^2 = 400 ms, so the spread is up to 400.
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100');
        $clock = new FrozenClock();
        $context = new RtryAttemptContext(3, $policy->attempts(), 0, 0, null);
        $error = HttpFailure::status(503, ['Retry-After' => '2']);

        $delays = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $scheduler = new RtryScheduler($clock, SeededRandom::randomizer($seed));
            $delays[] = (int) $scheduler->next($policy, $scheduler->outcome($error, $policy, $context), $context)->delayMs();
        }
        self::assertGreaterThanOrEqual(2000, min($delays));
        self::assertLessThanOrEqual(2400, max($delays));
        self::assertGreaterThan(2100, max($delays));
    }

    public function testTheSchedulersOutcomeKeepsTheSpreadInsideDl(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100;dl=2050ms');
        $clock = new FrozenClock();
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, 2050);
        $error = HttpFailure::status(503, ['Retry-After' => '2']);

        for ($seed = 1; $seed <= 50; $seed++) {
            $scheduler = new RtryScheduler($clock, SeededRandom::randomizer($seed));
            $decision = $scheduler->next($policy, $scheduler->outcome($error, $policy, $context), $context);
            self::assertTrue($decision->retry(), "seed $seed gave up");
            self::assertLessThan(2050, $decision->delayMs());
        }
    }

    public function testTheSchedulersOutcomeUsesThePolicysClassifier(): void
    {
        $classifier = RuleBasedFailureClassifier::default()->addRule(new InstanceOfRule(\LogicException::class, null, ['NETWORK_ERROR']));
        $policy = (new RtryPolicyFactory(null, $classifier))->fromSpec('rtry:a=3;d=100;j=0');
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, null);
        $scheduler = new RtryScheduler(new FrozenClock());

        $outcome = $scheduler->outcome(new \LogicException('pool exhausted'), $policy, $context);
        self::assertSame(['NETWORK_ERROR'], $outcome->tags());
        self::assertSame(100, $scheduler->next($policy, $outcome, $context)->delayMs());

        // A classifier passed in wins over the policy's.
        $plain = $scheduler->outcome(new \LogicException('pool exhausted'), $policy, $context, RuleBasedFailureClassifier::default());
        self::assertSame([], $plain->tags());
        self::assertSame(GiveUpReason::NotRetryable, $scheduler->next($policy, $plain, $context)->giveUpReason());
    }
}
