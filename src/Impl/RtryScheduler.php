<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\JitterInterface;
use Gohany\Retry\RetryDecision;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Retry\RetrySchedulerInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;
use Throwable;

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
 * Retry and outcome() keep their jitter spread under the ceiling, so a wait of exactly the
 * ceiling is honoured with or without jitter. Durable callers: build the outcome with this
 * scheduler's outcome(), so the ceiling is set in one place.
 */
final class RtryScheduler implements RetrySchedulerInterface
{
    /** The longest server-requested wait honoured by default: a day. */
    public const MAX_HEADER_WAIT_MS = 86_400_000;

    /** The spread above a server's wait is at most the larger of this and HEADER_SPREAD_PERCENT of the wait. */
    public const HEADER_SPREAD_MIN_MS = 1_000;

    /** The spread above a server's wait is at most the larger of HEADER_SPREAD_MIN_MS and this percent of the wait. */
    public const HEADER_SPREAD_PERCENT = 10;

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
     * Classifies an error the attempt numbered $context->attemptNumber() threw into the outcome
     * to pass to next(), for a durable retry. The one place a durable caller sets the ceiling,
     * the clock and the randomness: this scheduler's. A server's Retry-After is spread above,
     * never below, by the policy's jitter window for the next attempt, never past the context's
     * remainingBudgetMs() (dl) or this scheduler's ceiling when the server's own wait fits them.
     *
     * $classifier defaults to the policy's (RtryPolicy::failureClassifier()), else Rtry's default.
     */
    public function outcome(
        Throwable $error,
        RetryPolicyInterface $policy,
        AttemptContextInterface $context,
        ?FailureClassifierInterface $classifier = null,
    ): RtryAttemptOutcome {
        $classifier ??= $policy instanceof RtryPolicy ? $policy->failureClassifier() : RuleBasedFailureClassifier::default();
        return RtryAttemptOutcome::fromError(
            $error,
            $classifier,
            $this->clock,
            $policy,
            $context->attemptNumber(),
            $this->random,
            $context,
            $this->maxHeaderWaitMs,
        );
    }

    /**
     * How far above a server's Retry-After to wait, so callers told the same time do not
     * return together: the policy's jitter window on the backoff for the next attempt (capped),
     * drawn once. Full jitter of 100% on a 100 ms backoff spreads over [0, 100] ms above the
     * server's time; j=0 gives 0, the server's time exactly. Never below the server's time.
     *
     * Given the server's wait ($serverWaitMs, from now to its time), the spread is at most the
     * larger of HEADER_SPREAD_MIN_MS and HEADER_SPREAD_PERCENT of that wait: the server has
     * already said when to come back, and a whole backoff step on top (20 s after attempt 2 of
     * d=10s) pushes the next attempt toward dl for nothing. A wider window is scaled down to
     * the bound, not clipped at it, so the spread stays spread. Retry, outcome() and
     * RtryAttemptOutcome::fromError() pass the wait; without it the spread is 2.4.1's.
     */
    public static function headerSpreadMs(RetryPolicyInterface $policy, int $failedAttempt, Randomizer $random, ?int $serverWaitMs = null): int
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
        $jitter = $policy->jitter();
        $spread = abs($jitter->apply($backoff, $random) - $backoff);
        if ($serverWaitMs === null) {
            return $spread;
        }
        $bound = max(self::HEADER_SPREAD_MIN_MS, intdiv(max(0, $serverWaitMs), 100) * self::HEADER_SPREAD_PERCENT);
        $window = self::jitterWindowMs($jitter, $backoff);
        if ($window <= $bound) {
            // Within the bound already (or a jitter wider than its declared window: clip it).
            return min($spread, $bound);
        }
        return (int) floor(min($spread, $window) / $window * $bound);
    }

    /**
     * The widest spread $jitter can give on $backoff (gohany/retry SPEC 6.2): full jitter draws
     * from [max(0, D - w), D], pm from [D - w, D + w], none gives D.
     */
    private static function jitterWindowMs(JitterInterface $jitter, int $backoff): int
    {
        if ($jitter->mode() === JitterInterface::MODE_NONE) {
            return 0;
        }
        $percent = $jitter->percent();
        $window = $percent !== null ? (int) round($percent / 100.0 * $backoff) : max(0, (int) $jitter->windowMs());
        return $jitter->mode() === JitterInterface::MODE_PM ? $window : min($window, $backoff);
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
