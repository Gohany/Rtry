<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;
use Throwable;

/**
 * One attempt's result, or its error with the status, tags and Retry-After time the
 * classifier derived from it.
 */
final class RtryAttemptOutcome implements AttemptOutcomeInterface
{
    /** @param list<string> $tags */
    private function __construct(
        private readonly bool $success,
        private readonly mixed $result,
        private readonly ?Throwable $error,
        private readonly ?int $statusCode,
        private readonly array $tags,
        private readonly ?int $notBeforeUnixMs,
        private readonly ?bool $statusFromResponse = null,
    ) {
    }

    public static function success(mixed $result): self
    {
        return new self(true, $result, null, null, [], null);
    }

    /**
     * @param list<string> $tags
     * @param bool|null $statusFromResponse whether $statusCode was read from an HTTP response the
     *        error carries (true) or not (false: an exception code, a rule's constant); null when
     *        you do not say, and a breaker verdict then looks for a response on $error itself
     */
    public static function failure(Throwable $error, ?int $statusCode = null, array $tags = [], ?int $notBeforeUnixMs = null, ?bool $statusFromResponse = null): self
    {
        return new self(false, null, $error, $statusCode, array_values($tags), $notBeforeUnixMs, $statusFromResponse);
    }

    /**
     * Classifies a caught error (status, tags, Retry-After), for callers that schedule a
     * durable retry with RtryScheduler::next() instead of running Retry. Prefer
     * RtryScheduler::outcome(): it passes the scheduler's clock, randomness and ceiling, the
     * attempt number and the policy's classifier for you, so none of them is set twice.
     *
     * Pass the policy and the failed attempt's number so a server's Retry-After is spread
     * above, never below, by at most max(1 s, 10 %) of the server's wait
     * (RtryScheduler::headerSpreadMs()): rows told the same Retry-After then do not all come
     * due in the same millisecond. Leave $random null in production.
     *
     * Pass the context you will give RtryScheduler::next() too: when the server's own time
     * fits inside its remainingBudgetMs() (dl), the spread then stays inside it, as in Retry.
     * Without it the spread can push a wait that fits past dl, and next() gives up. The spread
     * also never pushes a wait that fits the scheduler's ceiling past it; pass
     * $maxHeaderWaitMs when your RtryScheduler has a ceiling other than the default day, or the
     * spread can push a wait that fits it over (a give-up) or be dropped at a longer one.
     */
    public static function fromError(
        Throwable $error,
        ?FailureClassifierInterface $classifier = null,
        ?ClockInterface $clock = null,
        ?RetryPolicyInterface $policy = null,
        int $failedAttempt = 1,
        ?Randomizer $random = null,
        ?AttemptContextInterface $context = null,
        int $maxHeaderWaitMs = RtryScheduler::MAX_HEADER_WAIT_MS,
    ): self {
        $metadata = ($classifier ?? RuleBasedFailureClassifier::default())->classify($error);
        $now = Clock::nowMs($clock ?? new NativeClock());
        $outcome = self::fromMetadata($error, $metadata, $now);
        if ($policy === null || !$policy->followHeaders() || $outcome->notBeforeUnixMs === null) {
            // Nothing to spread, so nothing drawn: a seeded durable run gives Retry's delays.
            return $outcome;
        }
        $remaining = $context?->remainingBudgetMs();
        $deadline = $remaining === null ? null : $now + HeaderWaits::clampDelta($remaining);
        return $outcome->spreadAboveServerTime(
            $now,
            RtryScheduler::headerSpreadMs(
                $policy,
                $failedAttempt,
                $random ?? new Randomizer(),
                $outcome->notBeforeUnixMs - $now,
                $outcome->roomAboveServerTimeMs($now, $deadline, $maxHeaderWaitMs),
            ),
            $deadline,
            $maxHeaderWaitMs,
        );
    }

    /**
     * A failure from classifier metadata. A relative Retry-After becomes an absolute time:
     * the later of the server's date and now + its delta. Waits beyond HeaderWaits' bounds are
     * pinned to them (the scheduler gives up on them), so no value can overflow. Rtry's own
     * FailureMetadata says whether its status was read from a response; other metadata leaves
     * statusFromResponse() null.
     */
    public static function fromMetadata(Throwable $error, FailureMetadataInterface $metadata, int $nowUnixMs): self
    {
        $notBefore = $metadata->getNotBeforeUnixMs();
        if ($notBefore !== null) {
            $notBefore = HeaderWaits::clampEpoch($notBefore);
        }
        $minDelay = $metadata->getMinNextDelayMs();
        if ($minDelay !== null) {
            $fromDelta = HeaderWaits::clampEpoch(max(0, $nowUnixMs) + HeaderWaits::clampDelta($minDelay));
            $notBefore = $notBefore === null ? $fromDelta : max($notBefore, $fromDelta);
        }
        $fromResponse = $metadata instanceof FailureMetadata ? $metadata->isFromResponse() : null;
        return self::failure($error, $metadata->getStatusCode(), $metadata->getTags(), $notBefore, $fromResponse);
    }

    /**
     * This failure with its server time moved $spreadMs later, when the server named a time
     * still ahead of $nowUnixMs. The spread never moves it to or past $deadlineUnixMs when the
     * server's own time is before it, and never past $nowUnixMs + $maxWaitMs when the server's
     * own wait is within that ceiling: whether a wait fits dl or the ceiling is decided by the
     * server's time, never by jitter. Otherwise this failure unchanged.
     *
     * @internal Retry and fromError() use it; gohany/retry SPEC 5.11 has the scheduler take
     *           max(delay, notBefore - now), so the spread is applied to notBefore.
     */
    public function spreadAboveServerTime(int $nowUnixMs, int $spreadMs, ?int $deadlineUnixMs = null, ?int $maxWaitMs = null): self
    {
        $notBefore = $this->notBeforeUnixMs;
        if ($this->success || $notBefore === null || $notBefore <= $nowUnixMs || $spreadMs <= 0) {
            return $this;
        }
        // Callers scale the draw into this room first (RtryScheduler::headerSpreadMs()), so the
        // cut only guards a draw from elsewhere.
        $spread = min($spreadMs, $this->roomAboveServerTimeMs($nowUnixMs, $deadlineUnixMs, $maxWaitMs));
        if ($spread <= 0) {
            return $this;
        }
        return new self(false, null, $this->error, $this->statusCode, $this->tags, $notBefore + $spread, $this->statusFromResponse);
    }

    /**
     * How far the spread may move this failure's server time: up to the epoch ceiling, to
     * $deadlineUnixMs - 1 when the server's own time is before it, and to $nowUnixMs +
     * $maxWaitMs (zero or below when the server's own wait is over it; the scheduler gives up
     * on that anyway). Null when this failure has no server time ahead of $nowUnixMs.
     *
     * @internal Retry and fromError() pass it to RtryScheduler::headerSpreadMs(), which scales
     *           its draw into it, so callers told a time close to dl do not all land on dl - 1 ms.
     */
    public function roomAboveServerTimeMs(int $nowUnixMs, ?int $deadlineUnixMs = null, ?int $maxWaitMs = null): ?int
    {
        $notBefore = $this->notBeforeUnixMs;
        if ($this->success || $notBefore === null || $notBefore <= $nowUnixMs) {
            return null;
        }
        $room = HeaderWaits::MAX_EPOCH_MS - $notBefore;
        if ($deadlineUnixMs !== null && $notBefore < $deadlineUnixMs) {
            $room = min($room, $deadlineUnixMs - 1 - $notBefore);
        }
        if ($maxWaitMs !== null) {
            $room = min($room, $nowUnixMs + HeaderWaits::clampDelta($maxWaitMs) - $notBefore);
        }
        return $room;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function notBeforeUnixMs(): ?int
    {
        return $this->notBeforeUnixMs;
    }

    /**
     * Whether statusCode() was read from an HTTP response the error carries: true, false (an
     * exception code or a rule's constant, never proof of HTTP), or null when not known.
     */
    public function statusFromResponse(): ?bool
    {
        return $this->statusFromResponse;
    }
}
