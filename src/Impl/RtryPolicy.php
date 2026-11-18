<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\HedgeInterface;
use Gohany\Retry\JitterInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Retry\SequenceInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Contracts\TokenDeciderInterface;
use Gohany\Rtry\Impl\Deciders\AlwaysRetryDecider;

final class RtryPolicy implements RtryPolicyInterface
{
    private int $attempts = 3;
    private ?int $attemptTimeoutMs = null;
    private ?int $deadlineBudgetMs = null;
    private int $startAfterMs = 0;
    private string $backoffMode = 'exp';
    private ?float $exponentialBase = 2.0;
    private ?int $delayMs = null;
    private bool $followHeaders = true;
    private int $capMs = 0;
    private int $cursor = 0;
    private array $retryOnTokens = [];
    private string $canonicalSpecification = '';
    private ?SequenceInterface $sequenceSpecification = null;
    private ?HedgeInterface $hedgeSpecification = null;
    private ?JitterInterface $jitterSpecification = null;
    private RetryDeciderInterface $retryDecider;
    private ?FailureClassifierInterface $failureClassifier = null;
    private ?int $seed = null;

    public function __construct(
        int                    $attempts = 3,
        ?int                   $attemptTimeoutMs = null,
        ?int                   $deadlineBudgetMs = null,
        int                    $startAfterMs = 0,
        string                 $backoffMode = 'exp',
        ?float                 $exponentialBase = 2.0,
        ?int                   $delayMs = null,
        bool                   $followHeaders = true,
        int                    $capMs = 0,
        ?SequenceInterface $sequenceSpecification = null,
        ?HedgeInterface    $hedgeSpecification = null,
        ?JitterInterface   $jitterSpecification = null,
        ?RetryDeciderInterface $retryDecider = null,
        ?FailureClassifierInterface $failureClassifier = null,
        string                 $canonicalSpecification = '',
        array                  $retryOnTokens = []
    ) {
        $this->attempts = $attempts;
        $this->attemptTimeoutMs = $attemptTimeoutMs;
        $this->deadlineBudgetMs = $deadlineBudgetMs;
        $this->startAfterMs = $startAfterMs;
        $this->backoffMode = $backoffMode;
        $this->exponentialBase = $exponentialBase;
        $this->delayMs = $delayMs;
        $this->sequenceSpecification = $sequenceSpecification;
        $this->followHeaders = $followHeaders;
        $this->capMs = $capMs;
        $this->hedgeSpecification = $hedgeSpecification;
        $this->jitterSpecification = $jitterSpecification;
        $this->retryDecider = $retryDecider ?? new AlwaysRetryDecider();
        $this->failureClassifier = $failureClassifier;
        $this->canonicalSpecification = $canonicalSpecification;
        $this->retryOnTokens = $retryOnTokens;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;
        return $this;
    }

    public function setAttemptTimeoutMs(?int $milliseconds): self
    {
        $this->attemptTimeoutMs = $milliseconds;
        return $this;
    }

    public function setDeadlineBudgetMs(?int $milliseconds): self
    {
        $this->deadlineBudgetMs = $milliseconds;
        return $this;
    }

    public function setStartAfterMs(int $milliseconds): self
    {
        $this->startAfterMs = $milliseconds;
        return $this;
    }

    public function setBackoffMode(string $mode): self
    {
        $this->backoffMode = $mode;
        return $this;
    }

    public function setExponentialBase(?float $base): self
    {
        $base !== null && $this->setBackoffMode(RtryPolicyInterface::BACKOFF_MODE_EXPONENTIAL);
        $this->exponentialBase = $base;
        return $this;
    }

    public function setDelayMs(?int $milliseconds): self
    {
        $this->setBackoffMode(RtryPolicyInterface::BACKOFF_MODE_LINEAR);
        $this->delayMs = $milliseconds;
        return $this;
    }

    public function setSequence(SequenceInterface $sequence): self
    {
        $this->setBackoffMode(RtryPolicyInterface::BACKOFF_MODE_SEQUENCE);
        $this->sequenceSpecification = $sequence;
        return $this;
    }

    public function setFollowHeaders(bool $follow): self
    {
        $this->followHeaders = $follow;
        return $this;
    }

    public function setRetryDecider(RetryDeciderInterface $decider): self
    {
        $this->retryDecider = $decider;

        if ($this->retryDecider instanceof TokenDeciderInterface && !empty($this->retryOnTokens)) {
            $this->retryDecider->setTokens($this->retryOnTokens);
        }

        return $this;
    }

    /**
     * @param array<int,int|string> $tokens
     */
    public function setRetryOnTokens(array $tokens): self
    {
        $this->retryOnTokens = $tokens;

        if ($this->retryDecider instanceof TokenDeciderInterface && !empty($this->retryOnTokens)) {
            $this->retryDecider->setTokens($this->retryOnTokens);
        }

        return $this;
    }

    public function setFailureClassifier(?FailureClassifierInterface $classifier): self
    {
        $this->failureClassifier = $classifier;
        return $this;
    }

    public function failureClassifier(): ?FailureClassifierInterface
    {
        return $this->failureClassifier;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function followHeaders(): bool
    {
        return $this->followHeaders;
    }

    public function backoffMode(): string
    {
        return $this->backoffMode;
    }

    public function exponentialBase(): ?float
    {
        return $this->exponentialBase;
    }

    public function delayMs(): ?int
    {
        return $this->delayMs;
    }

    public function attemptTimeoutMs(): ?int
    {
        return $this->attemptTimeoutMs;
    }

    public function deadlineBudgetMs(): ?int
    {
        return $this->deadlineBudgetMs;
    }

    public function startAfterMs(): int
    {
        if ($this->jitter() !== null) {
            return $this->jitter()->apply($this->startAfterMs, $this->seed);
        }
        return $this->startAfterMs;
    }

    public function capMs(): int
    {
        return $this->capMs;
    }

    public function setCapMs(int $milliseconds): self
    {
        $this->capMs = $milliseconds;
        return $this;
    }

    public function hedge(): ?HedgeInterface
    {
        return $this->hedgeSpecification;
    }

    public function jitter(): ?JitterInterface
    {
        return $this->jitterSpecification;
    }

    public function decider(): RetryDeciderInterface
    {
        return $this->retryDecider;
    }

    public function canonicalSpec(): string
    {
        return $this->canonicalSpecification;
    }

    public function sequence(): ?SequenceInterface
    {
        return $this->sequenceSpecification;
    }

    public function setHedgeSpec(HedgeInterface $spec): RtryPolicyInterface
    {
        $this->hedgeSpecification = $spec;
        return $this;
    }

    public function setJitterSpec(JitterInterface $jitter): RtryPolicyInterface
    {
        $this->jitterSpecification = $jitter;
        return $this;
    }

    public function setCanonicalSpec(string $specification): RtryPolicyInterface
    {
        $this->canonicalSpecification = $specification;
        return $this;
    }

    /**
     * The delay before $attemptNumber: the nominal delay, capped, jittered, and capped again
     * (plus/minus jitter can land above the cap). A cap of 0 or less means no cap.
     */
    public function nextDelayMs(?int $attemptNumber = null): int
    {
        $cap = $this->capMs();
        $delay = $this->generateNominalDeplayMs($attemptNumber);
        if ($cap > 0) {
            $delay = min($delay, $cap);
        }
        if ($this->jitter() !== null) {
            $delay = $this->jitter()->apply($delay, $this->seed);
        }
        return $cap > 0 ? min($delay, $cap) : $delay;
    }

    public function setSeed(?int $seed): self
    {
        $this->seed = $seed;
        return $this;
    }

    public function resetCursor(): void
    {
        $this->cursor = 0;
    }

    private function generateNominalDeplayMs(?int $attemptNumber = null): int
    {
        $attemptNumber = $attemptNumber === null ? $this->cursor++ : max(1, $attemptNumber);
        switch ($this->backoffMode()) {
            case 'lin':
                // d, 2d, 3d, ... before attempts 2, 3, 4, ...
                return max(1, $attemptNumber - 1) * max(0, $this->delayMs ?? 0);

            case 'seq':
                if ($this->sequence() === null) {
                    return 0;
                }

                return $this->sequence()->delayByPosition($attemptNumber) ?? 0;

            case 'exp':
            default:
                // d * b^(n-2) before attempt n (d, d*b, d*b^2, ...). A spec without d= keeps
                // the 1.0.x behaviour of growing from sa= instead.
                $initial = $this->delayMs ?? $this->startAfterMs;
                $base = $this->exponentialBase() ?? 2.0;
                $delay = $initial * pow($base, max(0, $attemptNumber - 2));
                return $delay >= PHP_INT_MAX ? PHP_INT_MAX : max(0, (int) round($delay));
        }
    }

}