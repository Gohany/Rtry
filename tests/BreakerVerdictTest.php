<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\CircuitOpenException;
use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Retry\Testing\TestOutcome;
use Gohany\Rtry\Contracts\BreakerVerdictInterface;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * withGuard()'s optional breaker verdict: the breaker is told whether the downstream service
 * failed, whatever the policy decides to retry (chunk chk_eeca7a4f1fa98461, found building
 * Foundation PR #829). Without a verdict nothing changes: a failure the policy does not retry
 * is a success for the breaker, a failure it retries is a failure.
 */
final class BreakerVerdictTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private FrozenClock $clock;
    private RecordingSleeper $sleeper;
    /** @var list<array{string,string,array<string,mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(self::T0);
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->logs = [];
    }

    private function retry(): Retry
    {
        $sink = function (string $level, string $message, array $context): void {
            $this->logs[] = [$level, $message, $context];
        };
        $logger = new class ($sink) extends AbstractLogger {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->sink)((string) $level, (string) $message, $context);
            }
        };
        return new Retry($this->clock, $this->sleeper, $logger, SeededRandom::randomizer(1118));
    }

    private function breaker(int $threshold = 2): CircuitBreaker
    {
        return new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, failureThreshold: $threshold, windowMs: 10_000, openMs: 5_000);
    }

    private static function refused(): \RuntimeException
    {
        return new \RuntimeException('connect ECONNREFUSED 10.0.0.7:443');
    }

    /** Runs $operation $times under $spec, guarded; returns the results. */
    private function runGuarded(Retry $retry, string $spec, \Closure $operation, int $times = 1): array
    {
        $policy = (new RtryPolicyFactory())->fromSpec($spec);
        $results = [];
        for ($i = 0; $i < $times; $i++) {
            $results[] = $retry->tryOutcome($operation, $policy);
        }
        return $results;
    }

    // ---- default unchanged ----------------------------------------------------------------

    public function testWithoutAVerdictAFailureThePolicyDoesNotRetryKeepsTheCircuitClosed(): void
    {
        $breaker = $this->breaker();
        $calls = 0;
        $op = static function () use (&$calls): never {
            $calls++;
            throw self::refused();
        };

        $this->runGuarded($this->retry()->withGuard('api', null, $breaker), 'rtry:a=3;d=100;j=0;on=5xx', $op, 5);

        self::assertSame(5, $calls);
        self::assertSame(CircuitState::Closed, $breaker->state('api'), 'as in 2.3: not retried counts as a success');
    }

    public function testWithoutAVerdictARetriedFailureCountsAgainstTheBreakerEvenWhenTheServiceAnswered(): void
    {
        // on=default,404 retries a 404: without a verdict that is a failure for the breaker.
        $breaker = $this->breaker();
        $op = static fn (): never => throw HttpFailure::status(404);

        [$result] = $this->runGuarded($this->retry()->withGuard('api', null, $breaker), 'rtry:a=4;d=100;j=0;on=default,404', $op);

        self::assertSame([2, GiveUpReason::CircuitOpen], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame([100], $this->sleeper->sleeps());
    }

    // ---- with ServiceFailureVerdict -------------------------------------------------------

    public function testATransportErrorThePolicyDoesNotRetryOpensTheCircuit(): void
    {
        $breaker = $this->breaker();
        $calls = 0;
        $op = static function () use (&$calls): never {
            $calls++;
            throw self::refused();
        };
        $retry = $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict());

        $results = $this->runGuarded($retry, 'rtry:a=3;d=100;j=0;on=5xx', $op, 3);

        self::assertSame(2, $calls, 'the third call is refused by the open circuit');
        self::assertSame([], $this->sleeper->sleeps(), 'the policy still retries nothing');
        self::assertSame(GiveUpReason::NotRetryable, $results[0]->giveUpReason());
        self::assertSame(GiveUpReason::CircuitOpen, $results[2]->giveUpReason());
        self::assertInstanceOf(CircuitOpenException::class, $results[2]->lastError());
        self::assertSame(CircuitState::Open, $breaker->state('api'));
    }

    public function testAProbeThatFailsWithANotRetriedTransportErrorReopensTheCircuit(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure('api');
        $breaker->recordFailure('api');
        $this->clock->advanceMs(5_000);
        $op = static fn (): never => throw self::refused();

        [$result] = $this->runGuarded($this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict()), 'rtry:a=3;d=100;j=0;on=5xx', $op);

        self::assertSame([1, GiveUpReason::NotRetryable], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame(CircuitState::Open, $breaker->state('api'), 'without a verdict this probe closed the circuit');
    }

    public function testAProbeThatFailsWithANotRetriedTransportErrorClosedTheCircuitWithoutAVerdict(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure('api');
        $breaker->recordFailure('api');
        $this->clock->advanceMs(5_000);
        $op = static fn (): never => throw self::refused();

        $this->runGuarded($this->retry()->withGuard('api', null, $breaker), 'rtry:a=3;d=100;j=0;on=5xx', $op);

        self::assertSame(CircuitState::Closed, $breaker->state('api'));
    }

    public function testARetried404IsTheServiceAnsweringAndKeepsTheCircuitClosed(): void
    {
        $breaker = $this->breaker();
        $op = static fn (): never => throw HttpFailure::status(404);

        [$result] = $this->runGuarded(
            $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict()),
            'rtry:a=4;d=100;j=0;on=default,404',
            $op,
        );

        self::assertSame([4, GiveUpReason::AttemptsExhausted], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame([100, 200, 400], $this->sleeper->sleeps(), 'the retry schedule is the policy\'s alone');
        self::assertSame(CircuitState::Closed, $breaker->state('api'));
    }

    public function testA503StillOpensTheCircuitAndEndsTheRunWithoutSleeping(): void
    {
        $breaker = $this->breaker();
        $op = static fn (): never => throw HttpFailure::status(503);

        [$result] = $this->runGuarded(
            $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict()),
            'rtry:a=4;d=100;j=0;on=5xx',
            $op,
        );

        self::assertSame([2, GiveUpReason::CircuitOpen], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame([100], $this->sleeper->sleeps());
    }

    public function testA422IsStillASuccessForTheBreaker(): void
    {
        $breaker = $this->breaker();
        $op = static fn (): never => throw HttpFailure::status(422);

        $this->runGuarded($this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict()), 'rtry:a=4;d=100;j=0', $op, 5);

        self::assertSame(CircuitState::Closed, $breaker->state('api'));
    }

    public function testASuccessIsStillASuccess(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure('api');
        $breaker->recordFailure('api');
        $this->clock->advanceMs(5_000);

        [$result] = $this->runGuarded($this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict()), 'rtry:a=3;d=100', static fn () => 'ok');

        self::assertSame('ok', $result->value());
        self::assertSame(CircuitState::Closed, $breaker->state('api'));
    }

    public function testTheVerdictSeesEachFailedAttemptsOutcome(): void
    {
        $verdict = new class implements BreakerVerdictInterface {
            /** @var list<array{?int, list<string>, string}> */
            public array $seen = [];

            public function isServiceFailure(AttemptOutcomeInterface $outcome): bool
            {
                $this->seen[] = [$outcome->statusCode(), $outcome->tags(), $outcome->error()?->getMessage() ?? ''];
                return false;
            }
        };
        $calls = 0;
        $op = static function () use (&$calls): never {
            $calls++;
            throw $calls === 1 ? HttpFailure::status(503) : self::refused();
        };

        $this->runGuarded($this->retry()->withGuard('api', null, $this->breaker(), $verdict), 'rtry:a=3;d=100;j=0;on=5xx', $op);

        self::assertSame([[503, [], 'HTTP 503'], [null, ['ECONNREFUSED'], 'connect ECONNREFUSED 10.0.0.7:443']], $verdict->seen);
    }

    public function testAVerdictThatThrowsFallsBackToThePolicysAnswerAndIsLoggedByClass(): void
    {
        $verdict = new class implements BreakerVerdictInterface {
            public function isServiceFailure(AttemptOutcomeInterface $outcome): bool
            {
                throw new \LogicException('secret=hunter2');
            }
        };
        $breaker = $this->breaker();
        $op = static fn (): never => throw HttpFailure::status(503);

        [$result] = $this->runGuarded($this->retry()->withGuard('api', null, $breaker, $verdict), 'rtry:a=4;d=100;j=0;on=5xx', $op);

        // The policy retries a 503, so the fallback reports a failure, as without a verdict.
        self::assertSame([2, GiveUpReason::CircuitOpen], [$result->attempts(), $result->giveUpReason()]);
        self::assertInstanceOf(HttpFailure::class, $result->lastError());
        $errors = array_values(array_filter($this->logs, static fn (array $l): bool => $l[1] === 'retry.guard_error'));
        self::assertCount(2, $errors);
        self::assertSame(['warning', 'retry.guard_error', ['guard' => 'breaker', 'call' => 'verdict', 'key' => 'api', 'exception_class' => \LogicException::class]], $errors[0]);
        self::assertStringNotContainsString('hunter2', serialize($this->logs));
    }

    public function testWithoutABreakerTheVerdictIsNeverAsked(): void
    {
        $verdict = new class implements BreakerVerdictInterface {
            public int $asked = 0;

            public function isServiceFailure(AttemptOutcomeInterface $outcome): bool
            {
                $this->asked++;
                return true;
            }
        };

        $this->runGuarded($this->retry()->withGuard('api', null, null, $verdict), 'rtry:a=3;d=100;j=0', static fn (): never => throw HttpFailure::status(503));

        self::assertSame(0, $verdict->asked);
    }

    public function testTheVerdictNeverChangesTheSleeps(): void
    {
        $op = static fn (): never => throw HttpFailure::status(503, ['Retry-After' => '2']);
        $spec = 'rtry:a=4;d=100';
        $breaker = static fn (FrozenClock $c): CircuitBreaker => new CircuitBreaker(new InMemoryCircuitBreakerStore($c), $c, failureThreshold: 50, windowMs: 10_000, openMs: 5_000);

        $this->runGuarded($this->retry()->withGuard('api', null, $breaker($this->clock)), $spec, $op);
        $without = $this->sleeper->sleeps();

        $this->setUp();
        $this->runGuarded($this->retry()->withGuard('api', null, $breaker($this->clock), new ServiceFailureVerdict()), $spec, $op);

        self::assertCount(3, $without);
        self::assertSame($without, $this->sleeper->sleeps());
    }

    // ---- ServiceFailureVerdict on its own -------------------------------------------------

    /** @return iterable<string, array{?int, list<string>, bool}> */
    public static function outcomes(): iterable
    {
        yield '408' => [408, [], true];
        yield '429' => [429, [], true];
        yield '500' => [500, [], true];
        yield '503' => [503, [], true];
        yield '599' => [599, [], true];
        yield '400' => [400, [], false];
        yield '404' => [404, [], false];
        yield '422' => [422, [], false];
        yield '403 plain' => [403, [], false];
        yield '403 rate limited' => [403, ['RATE_LIMITED'], true];
        yield '499' => [499, [], false];
        yield '600' => [600, [], false];
        yield '200' => [200, [], false];
        yield 'no status, no tags' => [null, [], false];
        yield 'ETIMEDOUT' => [null, ['ETIMEDOUT'], true];
        yield 'ECONNRESET' => [null, ['ECONNRESET'], true];
        yield 'ECONNREFUSED' => [null, ['ECONNREFUSED'], true];
        yield 'NETWORK_ERROR' => [null, ['NETWORK_ERROR'], true];
        yield 'a deadlock is the service answering' => [null, ['DEADLOCK'], false];
        yield 'SMTP 421 that timed out' => [421, ['ETIMEDOUT'], true];
        yield 'a tag of your own' => [null, ['SDK_TRANSPORT'], false];
    }

    /** @param list<string> $tags */
    #[DataProvider('outcomes')]
    public function testTheDefaultVerdict(?int $status, array $tags, bool $failure): void
    {
        $outcome = RtryAttemptOutcome::failure(new \RuntimeException('x'), $status, $tags);
        self::assertSame($failure, (new ServiceFailureVerdict())->isServiceFailure($outcome));
    }

    public function testExtraTagsCountAndAreMatchedWithoutCase(): void
    {
        $verdict = new ServiceFailureVerdict([...ServiceFailureVerdict::UNAVAILABLE_TAGS, 'sdk_transport']);

        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['SDK_TRANSPORT'])));
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['ETIMEDOUT'])));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), 404, ['DEADLOCK'])));
    }

    public function testTagsCanBeNarrowed(): void
    {
        $verdict = new ServiceFailureVerdict(['ECONNREFUSED']);

        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['ECONNREFUSED'])));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['ETIMEDOUT'])));
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), 502)), 'statuses still count');
    }

    public function testASuccessfulOutcomeIsNoFailure(): void
    {
        self::assertFalse((new ServiceFailureVerdict())->isServiceFailure(RtryAttemptOutcome::success('ok')));
        self::assertFalse((new ServiceFailureVerdict())->isServiceFailure(TestOutcome::success()));

        // An outcome of your own that succeeded but kept its 503 is still no failure.
        $succeededWithAStatus = new class implements AttemptOutcomeInterface {
            public function isSuccess(): bool
            {
                return true;
            }

            public function result(): mixed
            {
                return 'ok';
            }

            public function error(): ?\Throwable
            {
                return null;
            }

            public function statusCode(): ?int
            {
                return 503;
            }

            public function tags(): array
            {
                return ['ETIMEDOUT'];
            }

            public function notBeforeUnixMs(): ?int
            {
                return null;
            }
        };
        self::assertFalse((new ServiceFailureVerdict())->isServiceFailure($succeededWithAStatus));
    }

    public function testItReadsTheDefaultClassifiersOutcomes(): void
    {
        $verdict = new ServiceFailureVerdict();
        $classify = static function (\Throwable $e): RtryAttemptOutcome {
            return RtryAttemptOutcome::fromMetadata($e, RuleBasedFailureClassifier::default()->classify($e), self::T0);
        };

        self::assertTrue($verdict->isServiceFailure($classify(self::refused())));
        self::assertTrue($verdict->isServiceFailure($classify(new \RuntimeException('cURL error 28: Operation timed out'))));
        self::assertTrue($verdict->isServiceFailure($classify(HttpFailure::status(502))));
        self::assertTrue($verdict->isServiceFailure($classify(HttpFailure::status(403, ['Retry-After' => '60']))));
        self::assertFalse($verdict->isServiceFailure($classify(HttpFailure::status(404, [], 'GET /timeout timed out'))));
        self::assertFalse($verdict->isServiceFailure($classify(new \DomainException('Payment refused by issuer'))));
        self::assertFalse($verdict->isServiceFailure($classify(new \RuntimeException('SQLSTATE[40001]: Deadlock found'))));
    }
}
