<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptContextInterface;

final class RtryAttemptContext implements AttemptContextInterface
{
    /** @param array<string,mixed> $context */
    public function __construct(
        private readonly int $attemptNumber,
        private readonly int $maxAttempts,
        private readonly int $scheduledDelayMs,
        private readonly int $elapsedSinceFirstMs,
        private readonly ?int $remainingBudgetMs,
        private readonly array $context = [],
    ) {
    }

    public function attemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function scheduledDelayMs(): int
    {
        return $this->scheduledDelayMs;
    }

    public function elapsedSinceFirstMs(): int
    {
        return $this->elapsedSinceFirstMs;
    }

    public function remainingBudgetMs(): ?int
    {
        return $this->remainingBudgetMs;
    }

    public function context(): array
    {
        return $this->context;
    }
}
