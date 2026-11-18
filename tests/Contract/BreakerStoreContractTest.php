<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Contract;

use Gohany\Retry\CircuitBreakerStoreInterface;
use Gohany\Retry\Testing\CircuitBreakerStoreContractTestCase;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;

final class BreakerStoreContractTest extends CircuitBreakerStoreContractTestCase
{
    protected function createStore(): CircuitBreakerStoreInterface
    {
        return new InMemoryCircuitBreakerStore(new FrozenClock());
    }

    public function testAnExpiredKeyIsAbsent(): void
    {
        $clock = new FrozenClock();
        $store = new InMemoryCircuitBreakerStore($clock);
        $store->compareAndSet('k', null, 'v', 1_000);

        $clock->advanceMs(1_000);
        self::assertNull($store->load('k'));
        self::assertFalse($store->compareAndSet('k', 1, 'stale', 1_000));
        self::assertTrue($store->compareAndSet('k', null, 'fresh', 1_000));
    }
}
