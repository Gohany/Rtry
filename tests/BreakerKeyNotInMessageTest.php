<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitBreakerStoreInterface;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\VersionedValue;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\CircuitStateContendedException;
use PHPUnit\Framework\TestCase;

/**
 * Breaker keys can carry tenant or customer ids (payments.stripe.<tenantId>), and callers log
 * exception messages, so the error CircuitBreaker throws when its compare-and-set keeps losing
 * never names the key in its message: key() returns it (as gohany/retry's CircuitOpenException).
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
}
