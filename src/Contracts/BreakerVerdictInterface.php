<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

use Gohany\Retry\AttemptOutcomeInterface;

/**
 * What a failed attempt tells the circuit breaker. Pass one to Retry::withGuard().
 *
 * Without a verdict, Retry tells the breaker that a failure the policy retries is a failure and
 * one it does not retry is a success (the service answered). That is wrong for an error that
 * means the service never answered under a policy that does not retry it, such as a refused
 * connection under on=5xx: the circuit stays closed however many there are.
 *
 * A verdict changes only what the breaker is told. The scheduler's decision for each attempt
 * (retry or not, the delay, the give-up reason it gives) stays the policy's. Through the breaker
 * it can change whether the run goes on: a failure it reports can open the circuit, which ends
 * the run with CircuitOpen and refuses later calls, and a failure it calls a success can keep
 * the circuit closed where it would have opened.
 */
interface BreakerVerdictInterface
{
    /**
     * True when this failed attempt counts against the service's circuit. Asked once per failed
     * attempt, retried or not, and only when a breaker is set. Should not throw; if it does,
     * Retry falls back to $retryable for that attempt.
     *
     * @param bool $retryable the policy's answer: true when the policy retries this failure
     *        (its on= matches it), even when no attempt or time was left. It is what the breaker
     *        is told without a verdict: return true whenever it is true to only add failures.
     */
    public function isServiceFailure(AttemptOutcomeInterface $outcome, bool $retryable): bool;
}
