<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Budget;

use Gohany\Retry\RetryBudgetStoreInterface;
use Gohany\Rtry\Impl\Clock;
use Gohany\Rtry\Impl\NativeClock;
use Psr\Clock\ClockInterface;

/**
 * One process's budget state (SPEC 5.16). Entries expire after their TTL.
 */
final class InMemoryRetryBudgetStore implements RetryBudgetStoreInterface
{
    private readonly ClockInterface $clock;

    /** @var array<string,array{float,int}> value, expires at (epoch ms) */
    private array $values = [];

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new NativeClock();
    }

    public function add(string $key, float $delta, float $max, int $ttlMs): float
    {
        $now = Clock::nowMs($this->clock);
        $current = isset($this->values[$key]) && $this->values[$key][1] > $now ? $this->values[$key][0] : $max;
        $value = min($max, max(0.0, $current + $delta));
        $this->values[$key] = [$value, $now + $ttlMs];
        return $value;
    }
}
