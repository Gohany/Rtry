<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\RetryDecision;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Retry\RetrySchedulerInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

/**
 * SPEC 5.11: "retry after N ms" or "give up, because ...", never sleeping. Retry uses it
 * between attempts; durable retries (a row with nextAttemptAt, a queue message, a workflow
 * timer) call it directly and store the delay.
 *
 * Leave $random null in production: each process then gets its own unseeded Randomizer.
 * A seed shared by many workers would make them retry in step; seeds are for tests.
 */
final class RtryScheduler implements RetrySchedulerInterface
{
    private readonly ClockInterface $clock;
    private readonly Randomizer $random;

    public function __construct(?ClockInterface $clock = null, ?Randomizer $random = null)
    {
        $this->clock = $clock ?? new NativeClock();
        $this->random = $random ?? new Randomizer();
    }

    public function next(RetryPolicyInterface $policy, AttemptOutcomeInterface $outcome, AttemptContextInterface $context): RetryDecision
    {
        if ($outcome->isSuccess()) {
            throw new \InvalidArgumentException('A successful attempt has nothing to retry');
        }
        if (!$policy->decider()->shouldRetry($outcome, $context)) {
            return RetryDecision::giveUp(GiveUpReason::NotRetryable);
        }

        $failed = $context->attemptNumber();
        if ($failed >= $policy->attempts()) {
            return RetryDecision::giveUp(GiveUpReason::AttemptsExhausted);
        }

        $delay = $policy->nominalDelayMs($failed + 1);
        $cap = $policy->capMs();
        if ($cap !== null) {
            $delay = min($delay, $cap);
        }
        $delay = $policy->jitter()->apply($delay, $this->random);

        $notBefore = $outcome->notBeforeUnixMs();
        if ($policy->followHeaders() && $notBefore !== null) {
            $delay = max($delay, $notBefore - Clock::nowMs($this->clock));
        }

        $remaining = $context->remainingBudgetMs();
        if ($remaining !== null && $delay >= $remaining) {
            return RetryDecision::giveUp(GiveUpReason::DeadlineExceeded);
        }

        return RetryDecision::retryAfter(max(0, $delay));
    }
}
