<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Psr\Clock\ClockInterface;
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
     */
    public static function fromError(Throwable $error, ?FailureClassifierInterface $classifier = null, ?ClockInterface $clock = null): self
    {
        $metadata = ($classifier ?? RuleBasedFailureClassifier::default())->classify($error);
        return self::fromMetadata($error, $metadata, Clock::nowMs($clock ?? new NativeClock()));
    }

    /**
     * A failure from classifier metadata. A relative Retry-After becomes an absolute time:
     * the later of the server's date and now + its delta.
     */
    public static function fromMetadata(Throwable $error, FailureMetadataInterface $metadata, int $nowUnixMs): self
    {
        $notBefore = $metadata->getNotBeforeUnixMs();
        $minDelay = $metadata->getMinNextDelayMs();
        if ($minDelay !== null) {
            $fromDelta = $nowUnixMs + max(0, $minDelay);
            $notBefore = $notBefore === null ? $fromDelta : max($notBefore, $fromDelta);
        }
        return self::failure($error, $metadata->getStatusCode(), $metadata->getTags(), $notBefore);
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
