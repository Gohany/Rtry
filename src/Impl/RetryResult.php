<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\GiveUpReason;
use Gohany\Retry\RetryResultInterface;

/**
 * What Retry::tryOutcome() returns: the value, or the last error and why it gave up.
 */
final class RetryResult implements RetryResultInterface
{
    private function __construct(
        private readonly bool $success,
        private readonly mixed $value,
        private readonly ?\Throwable $lastError,
        private readonly int $attempts,
        private readonly ?GiveUpReason $giveUpReason,
    ) {
    }

    public static function success(mixed $value, int $attempts): self
    {
        return new self(true, $value, null, $attempts, null);
    }

    public static function gaveUp(\Throwable $lastError, int $attempts, GiveUpReason $reason): self
    {
        return new self(false, null, $lastError, $attempts, $reason);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function lastError(): ?\Throwable
    {
        return $this->lastError;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function giveUpReason(): ?GiveUpReason
    {
        return $this->giveUpReason;
    }
}
