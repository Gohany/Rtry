<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Impl\ErrorResponseLocator;
use Gohany\Rtry\Impl\FailureMetadata;

/**
 * The status of the HTTP response an error carries, wherever it sits on the error: see
 * ErrorResponseLocator. A reader passed here is tried first, then the default reading.
 * A response whose status is above 599 is still a response: no status, but no transport
 * tags either.
 */
final class ResponseStatusRule implements RuleInterface
{
    private readonly ErrorResponseLocator $locator;

    public function __construct(?ErrorResponseReaderInterface $reader = null)
    {
        $this->locator = ErrorResponseLocator::wrap($reader);
    }

    public function apply(\Throwable $e): ?FailureMetadata
    {
        $response = $this->locator->read($e);
        if ($response === null) {
            return null;
        }
        $status = $response->statusCode;
        return new FailureMetadata($status >= 100 && $status <= 599 ? $status : null, fromResponse: true);
    }
}
