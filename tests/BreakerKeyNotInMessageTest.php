<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitBreakerStoreInterface;
use Gohany\Retry\CircuitOpenException;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\VersionedValue;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\CircuitStateContendedException;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use PHPUnit\Framework\TestCase;

/**
 * Breaker keys can carry tenant or customer ids (payments.stripe.<tenantId>), and callers log
 * exception messages, so the error CircuitBreaker throws when its compare-and-set keeps losing
 * never names the key in its message: key() returns it (as gohany/retry's CircuitOpenException).
 *
 * Nor does the error every refused call gets, gohany/retry's CircuitOpenException: its
 * message names the key on gohany/retry 2.0.0 and not from 2.0.1, which composer.json requires.
 */
final class BreakerKeyNotInMessageTest extends TestCase
{
    private static function contendedStore(): CircuitBreakerStoreInterface
    {
        return new class () implements CircuitBreakerStoreInterface {
            public function load(string $key): ?VersionedValue
            {
                return null;
            }

            public function compareAndSet(string $key, ?int $expectedVersion, string $value, int $ttlMs): bool
            {
                return false;
            }
        };
    }

    public function testTheGiveUpMessageDoesNotCarryTheKey(): void
    {
        $key = 'payments.stripe.t_7Hq2mXnPz9kLwR4vB8cD1eF3gJ';
        $breaker = new CircuitBreaker(self::contendedStore(), new FrozenClock(), failureThreshold: 1);
        try {
            $breaker->recordFailure($key);
            self::fail('expected the compare-and-set to give up');
        } catch (CircuitStateContendedException $e) {
            self::assertStringNotContainsString('t_7Hq2mXnPz9kLwR4vB8cD1eF3gJ', $e->getMessage());
            self::assertStringNotContainsString('t_7Hq2mXnPz9kLwR4vB8cD1eF3gJ', (string) $e);
            self::assertSame($key, $e->key());
            self::assertInstanceOf(\RuntimeException::class, $e, 'callers catching RuntimeException still do');
        }
    }

    public function testTheMessageIsTheSameForEveryKey(): void
    {
        $messages = [];
        foreach (['search-api', 'payments.finix.t_other'] as $key) {
            try {
                (new CircuitBreaker(self::contendedStore(), new FrozenClock(), failureThreshold: 1))->recordFailure($key);
            } catch (CircuitStateContendedException $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertCount(2, $messages);
        self::assertSame($messages[0], $messages[1]);
    }

    public function testAnOpenCircuitRefusesWithoutTheKeyInTheMessage(): void
    {
        $key = 'payments.stripe.t_7Hq2mXnPz9kLwR4vB8cD1eF3gJ';
        $clock = new FrozenClock();
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, failureThreshold: 1);
        $breaker->recordFailure($key);
        $retry = (new Retry($clock, new RecordingSleeper($clock)))->withGuard($key, null, $breaker);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100');
        $ran = false;
        $operation = static function () use (&$ran): string {
            $ran = true;
            return 'ok';
        };

        try {
            $retry->try($operation, $policy);
            self::fail('expected the open circuit to refuse the call');
        } catch (CircuitOpenException $e) {
            self::assertStringNotContainsString('t_7Hq2mXnPz9kLwR4vB8cD1eF3gJ', $e->getMessage());
            self::assertStringNotContainsString('t_7Hq2mXnPz9kLwR4vB8cD1eF3gJ', (string) $e);
            self::assertSame($key, $e->key());
        }

        $result = $retry->tryOutcome($operation, $policy);
        self::assertSame(GiveUpReason::CircuitOpen, $result->giveUpReason());
        self::assertInstanceOf(CircuitOpenException::class, $result->lastError());
        self::assertStringNotContainsString('t_7Hq2mXnPz9kLwR4vB8cD1eF3gJ', $result->lastError()->getMessage());
        self::assertFalse($ran, 'refused, never run');
    }
}
