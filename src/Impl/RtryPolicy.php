<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\HedgeInterface;
use Gohany\Retry\JitterInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Retry\SequenceInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Impl\Deciders\OnTokensDecider;
use Gohany\Rtry\Impl\Parts\Jitter;

/**
 * An immutable retry policy (SPEC 5.3). It holds no cursor, seed or other iteration state:
 * every delay is a function of the attempt number, so one instance is safe to share across
 * requests in a long-running worker. Build one with RtryPolicyFactory::fromSpec() or the
 * constructor.
 */
final class RtryPolicy implements RetryPolicyInterface
{
    public const MODE_EXPONENTIAL = 'exp';
    public const MODE_LINEAR = 'lin';
    public const MODE_SEQUENCE = 'seq';

    /** Nominal delays are clamped here so b^n cannot overflow an int. */
    private const MAX_DELAY_MS = 9_000_000_000_000_000;

    private readonly JitterInterface $jitter;
    private readonly RetryDeciderInterface $decider;
    private readonly FailureClassifierInterface $failureClassifier;

    public function __construct(
        private readonly int $attempts,
        private readonly string $mode = self::MODE_EXPONENTIAL,
        private readonly ?int $delayMs = null,
        private readonly float $exponentialBase = 2.0,
        private readonly ?SequenceInterface $sequence = null,
        private readonly ?int $capMs = null,
        ?JitterInterface $jitter = null,
        private readonly ?int $attemptTimeoutMs = null,
        private readonly ?int $deadlineBudgetMs = null,
        ?RetryDeciderInterface $decider = null,
        private readonly int $startAfterMs = 0,
        private readonly bool $followHeaders = true,
        private readonly ?HedgeInterface $hedge = null,
        ?FailureClassifierInterface $failureClassifier = null,
        private readonly string $canonicalSpec = '',
    ) {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('attempts must be at least 1');
        }
        switch ($mode) {
            case self::MODE_EXPONENTIAL:
            case self::MODE_LINEAR:
                if ($delayMs === null || $delayMs < 0) {
                    throw new \InvalidArgumentException('A delay (d) is required in ' . $mode . ' mode');
                }
                if ($sequence !== null) {
                    throw new \InvalidArgumentException('A sequence is only allowed in seq mode');
                }
                break;
            case self::MODE_SEQUENCE:
                if ($sequence === null) {
                    throw new \InvalidArgumentException('seq mode needs a sequence');
                }
                if ($delayMs !== null) {
                    throw new \InvalidArgumentException('A delay (d) is not allowed in seq mode');
                }
                if (!$sequence->isRepeatLastDelay() && $attempts > count($sequence->delaysMs()) + 1) {
                    throw new \InvalidArgumentException('seq has too few delays for ' . $attempts . ' attempts; end it with * to repeat the last');
                }
                break;
            default:
                throw new \InvalidArgumentException('Unknown mode: ' . $mode);
        }
        if (!is_finite($exponentialBase) || $exponentialBase < 1.0) {
            throw new \InvalidArgumentException('The exponential factor (b) must be at least 1');
        }
        foreach (['cap' => $capMs, 't' => $attemptTimeoutMs, 'dl' => $deadlineBudgetMs, 'sa' => $startAfterMs] as $name => $ms) {
            if ($ms !== null && $ms < 0) {
                throw new \InvalidArgumentException($name . ' cannot be negative');
            }
        }

        $this->jitter = $jitter ?? Jitter::default();
        $this->decider = $decider ?? new OnTokensDecider();
        $this->failureClassifier = $failureClassifier ?? RuleBasedFailureClassifier::default();
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function exponentialBase(): float
    {
        return $this->exponentialBase;
    }

    /** Not jittered (SPEC 5.3). */
    public function startAfterMs(): int
    {
        return $this->startAfterMs;
    }

    public function delayMs(): ?int
    {
        return $this->delayMs;
    }

    public function nominalDelayMs(int $attemptNumber): int
    {
        if ($attemptNumber < 2) {
            throw new \InvalidArgumentException('There is no delay before attempt ' . $attemptNumber);
        }

        switch ($this->mode) {
            case self::MODE_LINEAR:
                $delay = (float) $this->delayMs * ($attemptNumber - 1);
                break;
            case self::MODE_SEQUENCE:
                \assert($this->sequence !== null);
                // Past the end of a non-repeating sequence (beyond attempts()), keep the last delay.
                $delays = $this->sequence->delaysMs();
                return $this->sequence->delayMsAt($attemptNumber) ?? (int) end($delays);
            case self::MODE_EXPONENTIAL:
            default:
                $delay = (float) $this->delayMs * $this->exponentialBase ** ($attemptNumber - 2);
        }

        return $delay >= self::MAX_DELAY_MS ? self::MAX_DELAY_MS : (int) round($delay);
    }

    public function capMs(): ?int
    {
        return $this->capMs;
    }

    public function jitter(): JitterInterface
    {
        return $this->jitter;
    }

    public function sequence(): ?SequenceInterface
    {
        return $this->sequence;
    }

    /** Advisory: PHP cannot pre-empt a running callable; pass it on to your client's timeout. */
    public function attemptTimeoutMs(): ?int
    {
        return $this->attemptTimeoutMs;
    }

    public function deadlineBudgetMs(): ?int
    {
        return $this->deadlineBudgetMs;
    }

    public function followHeaders(): bool
    {
        return $this->followHeaders;
    }

    /** A hint only: Rtry does not hedge. */
    public function hedge(): ?HedgeInterface
    {
        return $this->hedge;
    }

    public function decider(): RetryDeciderInterface
    {
        return $this->decider;
    }

    /** Turns a thrown error into a status code, tags and a Retry-After time. */
    public function failureClassifier(): FailureClassifierInterface
    {
        return $this->failureClassifier;
    }

    public function canonicalSpec(): string
    {
        return $this->canonicalSpec;
    }
}
