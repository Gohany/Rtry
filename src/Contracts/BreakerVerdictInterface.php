<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

use Gohany\Retry\AttemptOutcomeInterface;

/**
 * What a failed attempt tells the circuit breaker, decided by what happened to the downstream
 * service and not by whether the policy retries it. Pass one to Retry::withGuard().
 *
 * Without a verdict, Retry tells the breaker that a failure the policy retries is a failure and
 * one it does not retry is a success (the service answered). That is wrong for an error that
 * means the service never answered under a policy that does not retry it, such as a refused
 * connection under on=5xx: the circuit stays closed however many there are.
 */
interface BreakerVerdictInterface
{
    /**
     * True when this failed attempt counts against the service's circuit: it never answered
     * (a transport error) or answered with an outage (408, 429, 5xx). False when it answered
     * (a 404, a validation error). Asked once per failed attempt, retried or not, and only when
     * a breaker is set. Should not throw; if it does, Retry falls back to the policy's answer.
     */
    public function isServiceFailure(AttemptOutcomeInterface $outcome): bool;
}
