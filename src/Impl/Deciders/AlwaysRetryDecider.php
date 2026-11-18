<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Deciders;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;

/**
 * Retries every failure. Pass it to fromSpec() only for operations whose every error is
 * transient; it retries validation errors and 4xx too.
 */
final class AlwaysRetryDecider implements RetryDeciderInterface
{
    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        return !$outcome->isSuccess();
    }
}
