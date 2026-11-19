<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

use Gohany\Rtry\Impl\RtryPolicy;

/** A policy that delegates to an RtryPolicy but is not one. */
final class ForeignPolicy implements \Gohany\Retry\RetryPolicyInterface
{
    public function __construct(private readonly RtryPolicy $inner)
    {
    }

    public function attempts(): int
    {
        return $this->inner->attempts();
    }

    public function startAfterMs(): int
    {
        return $this->inner->startAfterMs();
    }

    public function delayMs(): ?int
    {
        return $this->inner->delayMs();
    }

    public function nominalDelayMs(int $attemptNumber): int
    {
        return $this->inner->nominalDelayMs($attemptNumber);
    }

    public function capMs(): ?int
    {
        return $this->inner->capMs();
    }

    public function jitter(): \Gohany\Retry\JitterInterface
    {
        return $this->inner->jitter();
    }

    public function sequence(): ?\Gohany\Retry\SequenceInterface
    {
        return $this->inner->sequence();
    }

    public function attemptTimeoutMs(): ?int
    {
        return $this->inner->attemptTimeoutMs();
    }

    public function deadlineBudgetMs(): ?int
    {
        return $this->inner->deadlineBudgetMs();
    }

    public function followHeaders(): bool
    {
        return $this->inner->followHeaders();
    }

    public function hedge(): ?\Gohany\Retry\HedgeInterface
    {
        return $this->inner->hedge();
    }

    public function decider(): \Gohany\Retry\RetryDeciderInterface
    {
        return $this->inner->decider();
    }

    public function canonicalSpec(): string
    {
        return $this->inner->canonicalSpec();
    }
}
