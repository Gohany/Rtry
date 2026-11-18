<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

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
    ) {
    }

    public static function success(mixed $result): self
    {
        return new self(true, $result, null, null, [], null);
    }

    /** @param list<string> $tags */
    public static function failure(Throwable $error, ?int $statusCode = null, array $tags = [], ?int $notBeforeUnixMs = null): self
    {
        return new self(false, null, $error, $statusCode, array_values($tags), $notBeforeUnixMs);
    }

    /**
     * Classifies a caught error (status, tags, Retry-After), for callers that schedule a
     * durable retry with RtryScheduler::next() instead of running Retry.
     *
     * Pass the policy and the failed attempt's number so a server's Retry-After is spread
     * above, never below (RtryScheduler::headerSpreadMs()): rows told the same Retry-After
     * then do not all come due in the same millisecond. Leave $random null in production.
     */
    public static function fromError(
        Throwable $error,
        ?FailureClassifierInterface $classifier = null,
        ?ClockInterface $clock = null,
        ?RetryPolicyInterface $policy = null,
        int $failedAttempt = 1,
        ?Randomizer $random = null,
    ): self {
        $metadata = ($classifier ?? RuleBasedFailureClassifier::default())->classify($error);
        $now = Clock::nowMs($clock ?? new NativeClock());
        $outcome = self::fromMetadata($error, $metadata, $now);
        if ($policy === null) {
            return $outcome;
        }
        return $outcome->spreadAboveServerTime(
            $now,
            RtryScheduler::headerSpreadMs($policy, $failedAttempt, $random ?? new Randomizer()),
        );
    }

    /**
     * A failure from classifier metadata. A relative Retry-After becomes an absolute time:
     * the later of the server's date and now + its delta. Waits beyond HeaderWaits' bounds are
     * pinned to them (the scheduler gives up on them), so no value can overflow.
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
        return self::failure($error, $metadata->getStatusCode(), $metadata->getTags(), $notBefore);
    }

    /**
     * This failure with its server time moved $spreadMs later, when the server named a time
     * still ahead of $nowUnixMs, but never to or past $deadlineUnixMs when the server's own
     * time is before it. Otherwise this failure unchanged.
     *
     * @internal Retry and fromError() use it; gohany/retry SPEC 5.11 has the scheduler take
     *           max(delay, notBefore - now), so the spread is applied to notBefore.
     */
    public function spreadAboveServerTime(int $nowUnixMs, int $spreadMs, ?int $deadlineUnixMs = null): self
    {
        $notBefore = $this->notBeforeUnixMs;
        if ($this->success || $notBefore === null || $notBefore <= $nowUnixMs || $spreadMs <= 0) {
            return $this;
        }
        $spread = min($spreadMs, HeaderWaits::MAX_EPOCH_MS - $notBefore);
        if ($deadlineUnixMs !== null && $notBefore < $deadlineUnixMs) {
            $spread = min($spread, $deadlineUnixMs - 1 - $notBefore);
        }
        if ($spread <= 0) {
            return $this;
        }
        return new self(false, null, $this->error, $this->statusCode, $this->tags, $notBefore + $spread);
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
}
