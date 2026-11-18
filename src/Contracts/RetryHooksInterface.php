<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface RetryHooksInterface
{
    /**
     * Runs after a failed attempt when we WILL retry, before sleeping.
     * $sleepMs is the chosen backoff for the upcoming sleep.
     *
     * Signature: function(
     *   AttemptContextInterface $context,
     *   AttemptOutcomeInterface $outcome,
     *   RetryPolicyInterface $policy,
     *   int $sleepMs,
     *   array $headers  rate-limit headers the classifier read, name => list of values
     * ): void
     */
    public function setBetweenAttemptsHook(callable $hook): void;

    /**
     * Runs when we give up after a failed attempt (not retryable, attempts exhausted,
     * deadline, or retry budget exhausted).
     *
     * Signature: function(
     *   AttemptContextInterface $context,
     *   AttemptOutcomeInterface $outcome,
     *   RetryPolicyInterface $policy,
     *   array $headers  rate-limit headers the classifier read, name => list of values
     * ): void
     */
    public function setOnGiveUpHook(callable $hook): void;
}