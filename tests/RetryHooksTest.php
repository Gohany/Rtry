<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RetryHooksTest extends TestCase
{
    private FrozenClock $clock;
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->sleeper = new RecordingSleeper($this->clock);
    }

    private function retry(?AbstractLogger $logger = null): Retry
    {
        return new Retry($this->clock, $this->sleeper, $logger, SeededRandom::randomizer(1));
    }

    public function testBetweenHookSeesTheSleepAboutToBeTaken(): void
    {
        $retry = $this->retry();
        $seen = [];
        $retry->setBetweenAttemptsHook(static function (AttemptContextInterface $c, AttemptOutcomeInterface $o, $policy, int $sleepMs, array $headers) use (&$seen): void {
            $seen[] = [$c->attemptNumber(), $o->statusCode(), $sleepMs, $headers];
        });

        $retry->try(static function (AttemptContextInterface $a): string {
            if ($a->attemptNumber() === 1) {
                throw HttpFailure::status(429, ['Retry-After' => '3']);
            }
            return 'ok';
        }, (new RtryPolicyFactory())->fromSpec('rtry:a=2;d=100'));

        // Full jitter spreads above the server's 3 s, by up to the 100 ms backoff.
        self::assertCount(1, $seen);
        [$attempt, $status, $sleepMs, $headers] = $seen[0];
        self::assertSame([1, 429, ['retry-after' => ['3']]], [$attempt, $status, $headers]);
        self::assertGreaterThanOrEqual(3000, $sleepMs);
        self::assertLessThanOrEqual(3100, $sleepMs);
        self::assertSame([$sleepMs], $this->sleeper->sleeps());
    }

    public function testGiveUpHookRunsOnceAndHookErrorsAreSwallowed(): void
    {
        $retry = $this->retry();
        $calls = 0;
        $retry->setOnGiveUpHook(static function () use (&$calls): void {
            $calls++;
            throw new \LogicException('a broken hook');
        });
        $retry->setBetweenAttemptsHook(static fn () => throw new \LogicException('another'));

        $result = $retry->tryOutcome(static fn () => throw HttpFailure::status(503), (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10;j=0'));

        self::assertSame(1, $calls);
        self::assertSame(3, $result->attempts());
    }

    public function testLogsNeverCarryTheErrorMessage(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed,string,array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        $this->retry($logger)->tryOutcome(
            static fn () => throw new \RuntimeException('timeout charging card 4242424242424242'),
            (new RtryPolicyFactory())->fromSpec('rtry:a=2;d=10')
        );

        self::assertNotEmpty($logger->records);
        self::assertStringNotContainsString('4242', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testWithGuardReturnsACopy(): void
    {
        $retry = $this->retry();
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, 1, 1_000, 1_000);
        $guarded = $retry->withGuard('svc', null, $breaker);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=1;d=10');

        $guarded->tryOutcome(static fn () => throw HttpFailure::status(503), $policy);

        self::assertNotSame($retry, $guarded);
        self::assertSame('ok', $retry->try(static fn () => 'ok', $policy), 'the unguarded retryer ignores the open breaker');
    }

    public function testANonRetryableFailureDoesNotTripTheBreaker(): void
    {
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, 1, 1_000, 1_000);
        $retry = $this->retry()->withGuard('svc', null, $breaker);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10');

        $retry->tryOutcome(static fn () => throw HttpFailure::status(404), $policy);

        self::assertTrue($breaker->allow('svc'));
    }
}
