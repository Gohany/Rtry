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
 *
 * A server's wait (notBeforeUnixMs) is honoured even above the cap (SPEC 5.11 step 5), up to
 * $maxHeaderWaitMs (a day by default). A longer one gives up with DeadlineExceeded instead of
 * sleeping or scheduling it: no in-process caller should sleep that long, and a header asking
 * for a year is more likely a bug than a plan. Durable callers that do want longer pass more.
 * Retry and RtryAttemptOutcome::fromError() keep their jitter spread under the ceiling, so a
 * wait of exactly the ceiling is honoured with or without jitter.
 */
final class RtryScheduler implements RetrySchedulerInterface
{
    /** The longest server-requested wait honoured by default: a day. */
    public const MAX_HEADER_WAIT_MS = 86_400_000;

    private readonly ClockInterface $clock;
    private readonly Randomizer $random;

    public function __construct(
        ?ClockInterface $clock = null,
        ?Randomizer $random = null,
        private readonly int $maxHeaderWaitMs = self::MAX_HEADER_WAIT_MS,
    ) {
        if ($maxHeaderWaitMs < 0) {
            throw new \InvalidArgumentException('maxHeaderWaitMs cannot be negative: ' . $maxHeaderWaitMs);
        }
        $this->clock = $clock ?? new NativeClock();
        $this->random = $random ?? new Randomizer();
    }

    /** The longest server-requested wait this scheduler honours; a longer one gives up. */
    public function maxHeaderWaitMs(): int
    {
        return $this->maxHeaderWaitMs;
    }

    /**
     * How far above a server's Retry-After to wait, so callers told the same time do not
     * return together: the policy's jitter window on the backoff for the next attempt (capped),
     * drawn once. Full jitter of 100% on a 100 ms backoff spreads over [0, 100] ms above the
     * server's time; j=0 gives 0, the server's time exactly. Never below the server's time.
     */
    public static function headerSpreadMs(RetryPolicyInterface $policy, int $failedAttempt, Randomizer $random): int
    {
        if ($failedAttempt >= $policy->attempts()) {
            return 0;
        }
        $backoff = $policy->nominalDelayMs($failedAttempt + 1);
        $cap = $policy->capMs();
        if ($cap !== null) {
            $backoff = min($backoff, $cap);
        }
        $backoff = max(0, $backoff);
        return abs($policy->jitter()->apply($backoff, $random) - $backoff);
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
            $now = Clock::nowMs($this->clock);
            if ($notBefore > $now) {
                $wait = $notBefore - $now;
                if (!is_int($wait) || $wait > $this->maxHeaderWaitMs) {
                    return RetryDecision::giveUp(GiveUpReason::DeadlineExceeded);
                }
                $delay = max($delay, $wait);
            }
        }

        $remaining = $context->remainingBudgetMs();
        if ($remaining !== null && $delay >= $remaining) {
            return RetryDecision::giveUp(GiveUpReason::DeadlineExceeded);
        }

        return RetryDecision::retryAfter(max(0, $delay));
    }
}
