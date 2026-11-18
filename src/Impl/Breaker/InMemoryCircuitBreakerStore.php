<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Breaker;

use Gohany\Retry\CircuitBreakerStoreInterface;
use Gohany\Retry\VersionedValue;
use Gohany\Rtry\Impl\Clock;
use Gohany\Rtry\Impl\NativeClock;
use Psr\Clock\ClockInterface;

/**
 * One process's breaker state (SPEC 5.19): a versioned compare-and-set per key. Entries
 * expire after their TTL.
 */
final class InMemoryCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    private readonly ClockInterface $clock;

    /** @var array<string,array{int,string,int}> version, value, expires at (epoch ms) */
    private array $rows = [];

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new NativeClock();
    }

    public function load(string $key): ?VersionedValue
    {
        $row = $this->live($key);
        return $row === null ? null : new VersionedValue($row[0], $row[1]);
    }

    public function compareAndSet(string $key, ?int $expectedVersion, string $value, int $ttlMs): bool
    {
        $current = $this->live($key)[0] ?? null;
        if ($current !== $expectedVersion) {
            return false;
        }
        $this->rows[$key] = [($current ?? 0) + 1, $value, Clock::nowMs($this->clock) + $ttlMs];
        return true;
    }

    /** @return array{int,string,int}|null */
    private function live(string $key): ?array
    {
        if (!isset($this->rows[$key])) {
            return null;
        }
        if ($this->rows[$key][2] <= Clock::nowMs($this->clock)) {
            unset($this->rows[$key]);
            return null;
        }
        return $this->rows[$key];
    }
}
