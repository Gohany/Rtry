<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
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
 * A give-up is ordinary: the caller gets the error back and decides how loud to be. Rtry logs
 * a failure the policy does not retry (a 404, a validation error) at debug, a retryable run
 * that gave up at warning, and nothing at error. Never the message.
 */
final class GiveUpLoggingTest extends TestCase
{
    private FrozenClock $clock;
    private RecordingSleeper $sleeper;
    /** @var list<array{string,string,array<string,mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
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

    /** @return list<string> "level message" for each record */
    private function lines(): array
    {
        return array_map(static fn (array $r): string => $r[0] . ' ' . $r[1], $this->logs);
    }

    private static function throws(\Throwable $error): \Closure
    {
        return static function () use ($error): never {
            throw $error;
        };
    }

    public function testANonRetryable404IsLoggedAtDebugOnly(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');

        $this->retry()->tryOutcome(self::throws(HttpFailure::status(404)), $policy);

        self::assertSame(['debug retry.attempt', 'debug retry.failure', 'debug retry.give_up'], $this->lines());
        self::assertSame('not_retryable', $this->logs[2][2]['reason']);
        self::assertSame([], $this->sleeper->sleeps());
    }

    public function testRetryableFailuresThatRunOutAreWarningsNotErrors(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');

        $this->retry()->tryOutcome(self::throws(HttpFailure::status(503)), $policy);

        self::assertSame([
            'debug retry.attempt', 'warning retry.failure',
            'debug retry.attempt', 'warning retry.failure',
            'debug retry.attempt', 'warning retry.failure',
            'warning retry.give_up',
        ], $this->lines());
        self::assertSame('attempts_exhausted', $this->logs[6][2]['reason']);
        self::assertSame([100, 200], $this->sleeper->sleeps());
    }

    public function testACircuitOpenGiveUpIsAWarning(): void
    {
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, failureThreshold: 1, windowMs: 10_000, openMs: 5_000);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');

        $this->retry()->withGuard('svc', null, $breaker)->tryOutcome(self::throws(HttpFailure::status(503)), $policy);

        $giveUps = array_values(array_filter($this->logs, static fn (array $r): bool => $r[1] === 'retry.give_up'));
        self::assertCount(1, $giveUps);
        self::assertSame('warning', $giveUps[0][0]);
        self::assertSame('circuit_open', $giveUps[0][2]['reason']);
    }

    public function testNothingIsLoggedAtErrorAndNoRecordCarriesTheMessage(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=2;d=100;j=0');
        $secret = 'GET https://api.example.test/v1/customers?email=someone%40example.test';

        $this->retry()->tryOutcome(self::throws(HttpFailure::status(422, [], $secret)), $policy);
        $this->retry()->tryOutcome(self::throws(HttpFailure::status(500, [], $secret)), $policy);
        $this->retry()->tryOutcome(self::throws(new \RuntimeException($secret . ' timed out')), $policy);

        foreach ($this->logs as [$level, $message, $context]) {
            self::assertNotSame('error', $level, $message);
            self::assertStringNotContainsString('example.test', json_encode($context, JSON_THROW_ON_ERROR), $message);
        }
        self::assertContains(HttpFailure::class, array_column(array_column($this->logs, 2), 'exception_class'));
    }

    /** @return list<array{string,string,array<string,mixed>}> records named $message */
    private function records(string $message): array
    {
        return array_values(array_filter($this->logs, static fn (array $r): bool => $r[1] === $message));
    }

    public function testADeadlineGiveUpAndItsLastFailureAreWarnings(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=10;d=100;j=0;dl=350');

        $this->retry()->tryOutcome(self::throws(HttpFailure::status(503)), $policy);

        $giveUps = $this->records('retry.give_up');
        self::assertSame([['warning', 'deadline_exceeded']], array_map(static fn (array $r): array => [$r[0], $r[2]['reason']], $giveUps));
        $failures = $this->records('retry.failure');
        self::assertCount(3, $failures);
        self::assertSame(['warning', 'warning', 'warning'], array_column($failures, 0));
    }

    public function testABudgetGiveUpIsAWarning(): void
    {
        $budget = new TokenBucketRetryBudget(new InMemoryRetryBudgetStore($this->clock), 4, 0.1);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=10;d=100;j=0');

        $this->retry()->withGuard('svc', $budget)->tryOutcome(self::throws(HttpFailure::status(503)), $policy);

        $giveUps = $this->records('retry.give_up');
        self::assertSame([['warning', 'budget_exhausted']], array_map(static fn (array $r): array => [$r[0], $r[2]['reason']], $giveUps));
        self::assertNotContains('error', array_column($this->logs, 0));
    }

    public function testACircuitOpenBeforeAnyAttemptIsAWarning(): void
    {
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, failureThreshold: 1, windowMs: 10_000, openMs: 5_000);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');
        $retry = $this->retry()->withGuard('svc', null, $breaker);
        $retry->tryOutcome(self::throws(HttpFailure::status(503)), $policy);
        $this->logs = [];

        $result = $retry->tryOutcome(self::throws(HttpFailure::status(503)), $policy);

        self::assertSame(0, $result->attempts());
        self::assertSame(['warning retry.give_up'], $this->lines());
        self::assertSame('circuit_open', $this->logs[0][2]['reason']);
    }

    public function testAnAnonymousExceptionClassIsLoggedWithoutItsFilePath(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=1;d=100;j=0');
        $error = new class ('Connection refused') extends \RuntimeException {
        };

        $this->retry()->tryOutcome(self::throws($error), $policy);

        self::assertSame('RuntimeException@anonymous', $this->records('retry.failure')[0][2]['exception_class']);
        self::assertSame('RuntimeException@anonymous', $this->records('retry.give_up')[0][2]['last_error_class']);
        self::assertStringNotContainsString(__FILE__, json_encode($this->logs, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
