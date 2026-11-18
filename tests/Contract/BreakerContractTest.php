<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Contract;

use Gohany\Retry\CircuitBreakerInterface;
use Gohany\Retry\Testing\CircuitBreakerContractTestCase;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;

final class BreakerContractTest extends CircuitBreakerContractTestCase
{
    protected function createBreaker(FrozenClock $clock, int $failureThreshold, int $windowMs, int $openMs): CircuitBreakerInterface
    {
        return new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, $failureThreshold, $windowMs, $openMs);
    }

    protected function createSharedBreakers(FrozenClock $clock, int $failureThreshold, int $windowMs, int $openMs): array
    {
        $store = new InMemoryCircuitBreakerStore($clock);
        return [
            new CircuitBreaker($store, $clock, $failureThreshold, $windowMs, $openMs),
            new CircuitBreaker($store, $clock, $failureThreshold, $windowMs, $openMs),
        ];
    }
}
