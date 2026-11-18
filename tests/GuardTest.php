<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitBreakerInterface;
use Gohany\Retry\CircuitBreakerStoreInterface;
use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\RetryBudgetInterface;
use Gohany\Retry\RetryBudgetStoreInterface;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Retry\VersionedValue;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Budget\InMemoryRetryBudgetStore;
use Gohany\Rtry\Impl\Budget\TokenBucketRetryBudget;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Retry with a guard (withGuard): no sleep once the circuit is open, and a failing breaker or
 * budget (its store down, say) never replaces the operation's own result or error.
 *
 * The rule under test: a breaker that fails lets the attempt run (fail open); a budget that
 * fails refuses the retry (fail closed); a failed report (recordSuccess/recordFailure) is
 * ignored. Every one is logged as retry.guard_error, class only.
 */
final class GuardTest extends TestCase
{
    private const T0 = 1_800_000_000_000;
    private const SPEC = 'rtry:a=4;d=100;j=0;on=5xx';

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

    private function breaker(CircuitBreakerStoreInterface $store, int $threshold = 2): CircuitBreaker
    {
        return new CircuitBreaker($store, $this->clock, failureThreshold: $threshold, windowMs: 10_000, openMs: 5_000);
    }

    /** @return \Closure(): never */
    private static function failing(int $status, int &$calls): \Closure
    {
        return static function () use ($status, &$calls): never {
            $calls++;
            throw HttpFailure::status($status);
        };
    }

    /** Fails $failures times with a 503, then returns 'ok'. */
    private static function flaky(int $failures, int &$calls): \Closure
    {
        return static function () use ($failures, &$calls): string {
            $calls++;
            if ($calls <= $failures) {
                throw HttpFailure::status(503);
            }
            return 'ok';
        };
    }

    /** @return list<array{string,string,array<string,mixed>}> */
    private function guardErrors(): array
    {
        return array_values(array_filter($this->logs, static fn (array $l): bool => $l[1] === 'retry.guard_error'));
    }

    private function brokenBreakerStore(): CircuitBreakerStoreInterface
    {
        return new class implements CircuitBreakerStoreInterface {
            public function load(string $key): ?VersionedValue
            {
                throw new \RuntimeException('connection refused to 10.0.0.7:6379 password=hunter2');
            }

            public function compareAndSet(string $key, ?int $expectedVersion, string $value, int $ttlMs): bool
            {
                throw new \RuntimeException('connection refused');
            }
        };
    }

    private function brokenBudgetStore(): RetryBudgetStoreInterface
    {
        return new class implements RetryBudgetStoreInterface {
            public function add(string $key, float $delta, float $max, int $ttlMs): float
            {
                throw new \RuntimeException('connection refused');
            }
        };
    }

    /**
     * A breaker whose named methods throw; the rest behave as an always-closed breaker.
     *
     * @param list<string> $throwing
     */
    private function throwingBreaker(array $throwing): CircuitBreakerInterface
    {
        return new class ($throwing) implements CircuitBreakerInterface {
            /** @param list<string> $throwing */
            public function __construct(private readonly array $throwing)
            {
            }

            private function maybeThrow(string $method): void
            {
                if (in_array($method, $this->throwing, true)) {
                    throw new \RuntimeException($method . ' failed');
                }
            }

            public function allow(string $key): bool
            {
                $this->maybeThrow('allow');
                return true;
            }

            public function recordSuccess(string $key): void
            {
                $this->maybeThrow('recordSuccess');
            }

            public function recordFailure(string $key): void
            {
                $this->maybeThrow('recordFailure');
            }

            public function state(string $key): CircuitState
            {
                $this->maybeThrow('state');
                return CircuitState::Closed;
            }
        };
    }

    // ---- No sleep once the circuit is open --------------------------------------------

    public function testTheFailureThatOpensTheCircuitEndsTheRunWithoutSleeping(): void
    {
        $budget = new TokenBucketRetryBudget(new InMemoryRetryBudgetStore($this->clock));
        $retry = $this->retry()->withGuard('api', $budget, $this->breaker(new InMemoryCircuitBreakerStore($this->clock)));
        $gaveUp = 0;
        $retry->setOnGiveUpHook(static function () use (&$gaveUp): void {
            $gaveUp++;
        });
        $calls = 0;

        $result = $retry->tryOutcome(self::failing(503, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame([2, GiveUpReason::CircuitOpen], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame(2, $calls);
        self::assertSame([100], $this->sleeper->sleeps(), 'no wait for a retry the open circuit would refuse');
        self::assertInstanceOf(HttpFailure::class, $result->lastError(), 'the real error, not CircuitOpenException');
        self::assertSame(1, $gaveUp, 'the give-up hook ran once');
        self::assertSame(9.0, $budget->tokens('api'), 'one token for the one retry; none for the refused one');
    }

    public function testTryRethrowsTheRealErrorWhenTheCircuitOpensMidRun(): void
    {
        $retry = $this->retry()->withGuard('api', null, $this->breaker(new InMemoryCircuitBreakerStore($this->clock)));
        $calls = 0;
        try {
            $retry->try(self::failing(503, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));
            self::fail('expected the 503');
        } catch (HttpFailure $e) {
            self::assertSame(503, $e->getResponse()->getStatusCode());
        }
        self::assertSame([100], $this->sleeper->sleeps());
    }

    public function testAFailedProbeEndsTheRunWithoutSleeping(): void
    {
        $breaker = $this->breaker(new InMemoryCircuitBreakerStore($this->clock));
        $breaker->recordFailure('api');
        $breaker->recordFailure('api');
        $this->clock->advanceMs(5_000);
        $calls = 0;

        $result = $this->retry()->withGuard('api', null, $breaker)
            ->tryOutcome(self::failing(503, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame([1, GiveUpReason::CircuitOpen], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame([], $this->sleeper->sleeps());
        self::assertSame(CircuitState::Open, $breaker->state('api'));
    }

    public function testACircuitOpenedElsewhereDuringTheAttemptEndsTheRunWithoutSleeping(): void
    {
        $store = new InMemoryCircuitBreakerStore($this->clock);
        $ours = $this->breaker($store, 3);
        $otherWorker = $this->breaker($store, 3);
        $calls = 0;
        $operation = static function () use ($otherWorker, &$calls): never {
            $calls++;
            $otherWorker->recordFailure('api');
            $otherWorker->recordFailure('api');
            throw HttpFailure::status(502);
        };

        $result = $this->retry()->withGuard('api', null, $ours)
            ->tryOutcome($operation, (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame([1, GiveUpReason::CircuitOpen], [$result->attempts(), $result->giveUpReason()]);
        self::assertSame([], $this->sleeper->sleeps());
    }

    public function testAClosedCircuitStillRetriesOnTheSpecSchedule(): void
    {
        $breaker = $this->breaker(new InMemoryCircuitBreakerStore($this->clock), 10);
        $calls = 0;

        $value = $this->retry()->withGuard('api', null, $breaker)
            ->try(self::flaky(3, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame('ok', $value);
        self::assertSame([100, 200, 400], $this->sleeper->sleeps());
        self::assertSame(CircuitState::Closed, $breaker->state('api'));
    }

    // ---- Guard failures never hide the outcome ----------------------------------------

    public function testABreakerWhoseStoreIsDownFailsOpen(): void
    {
        $calls = 0;
        $value = $this->retry()->withGuard('api', null, $this->breaker($this->brokenBreakerStore()))
            ->try(self::flaky(2, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame('ok', $value, 'the operation ran and its value came back');
        self::assertSame(3, $calls);
        self::assertSame([100, 200], $this->sleeper->sleeps(), 'the policy still bounds the retries');
        self::assertNotSame([], $this->guardErrors());
    }

    public function testABreakerWhoseStoreIsDownReturnsTheOperationsRealErrorOnExhaustion(): void
    {
        $calls = 0;
        $result = $this->retry()->withGuard('api', null, $this->breaker($this->brokenBreakerStore()))
            ->tryOutcome(self::failing(503, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame([4, GiveUpReason::AttemptsExhausted], [$result->attempts(), $result->giveUpReason()]);
        self::assertInstanceOf(HttpFailure::class, $result->lastError());
        self::assertSame([100, 200, 400], $this->sleeper->sleeps());
    }

    public function testANotRetryableErrorSurvivesABrokenBreaker(): void
    {
        $calls = 0;
        $result = $this->retry()->withGuard('api', null, $this->breaker($this->brokenBreakerStore()))
            ->tryOutcome(self::failing(404, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame([1, GiveUpReason::NotRetryable], [$result->attempts(), $result->giveUpReason()]);
        self::assertInstanceOf(HttpFailure::class, $result->lastError());
        self::assertSame(404, $result->lastError()->getResponse()->getStatusCode());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function breakerMethods(): iterable
    {
        yield 'allow' => [['allow']];
        yield 'recordSuccess' => [['recordSuccess']];
        yield 'recordFailure' => [['recordFailure']];
        yield 'state' => [['state']];
        yield 'all of them' => [['allow', 'recordSuccess', 'recordFailure', 'state']];
    }

    /**
     * @param list<string> $throwing
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('breakerMethods')]
    public function testAnyBreakerMethodThatThrowsLeavesTheOutcomeAlone(array $throwing): void
    {
        $calls = 0;
        $value = $this->retry()->withGuard('api', null, $this->throwingBreaker($throwing))
            ->try(self::flaky(2, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame('ok', $value);
        self::assertSame([100, 200], $this->sleeper->sleeps());
        foreach ($this->guardErrors() as [$level, , $data]) {
            self::assertSame('warning', $level);
            self::assertSame('breaker', $data['guard']);
            self::assertContains($data['call'], $throwing);
        }
        self::assertNotSame([], $this->guardErrors());
    }

    public function testABudgetWhoseStoreIsDownFailsClosed(): void
    {
        $budget = new TokenBucketRetryBudget($this->brokenBudgetStore());
        $calls = 0;
        $result = $this->retry()->withGuard('api', $budget)
            ->tryOutcome(self::failing(503, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame([1, GiveUpReason::BudgetExhausted], [$result->attempts(), $result->giveUpReason()]);
        self::assertInstanceOf(HttpFailure::class, $result->lastError(), 'the real error, not the store error');
        self::assertSame([], $this->sleeper->sleeps(), 'no retry while the budget cannot be checked');
    }

    public function testASuccessIsReturnedWhenTheBudgetCannotRecordIt(): void
    {
        $budget = new TokenBucketRetryBudget($this->brokenBudgetStore());
        $value = $this->retry()->withGuard('api', $budget)
            ->try(static fn (): string => 'ok', (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame('ok', $value);
        self::assertSame([['warning', 'retry.guard_error']], array_map(
            static fn (array $l): array => [$l[0], $l[1]],
            $this->guardErrors(),
        ));
        self::assertSame('budget', $this->guardErrors()[0][2]['guard']);
        self::assertSame('recordSuccess', $this->guardErrors()[0][2]['call']);
    }

    public function testBothGuardsDownStillReturnTheOperationsValue(): void
    {
        $budget = new TokenBucketRetryBudget($this->brokenBudgetStore());
        $value = $this->retry()->withGuard('api', $budget, $this->breaker($this->brokenBreakerStore()))
            ->try(static fn (): string => 'ok', (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertSame('ok', $value);
        self::assertSame([], $this->sleeper->sleeps());
    }

    public function testAGuardErrorIsLoggedByClassNeverByMessage(): void
    {
        $calls = 0;
        $this->retry()->withGuard('api', null, $this->breaker($this->brokenBreakerStore()))
            ->tryOutcome(self::flaky(1, $calls), (new RtryPolicyFactory())->fromSpec(self::SPEC));

        self::assertNotSame([], $this->guardErrors());
        foreach ($this->guardErrors() as [, , $data]) {
            self::assertSame(\RuntimeException::class, $data['exception_class']);
            self::assertSame('api', $data['key']);
        }
        self::assertStringNotContainsString('hunter2', serialize($this->logs));
        self::assertStringNotContainsString('connection refused', serialize($this->logs));
    }
}
