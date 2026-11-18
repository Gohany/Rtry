<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Impl\Psr7ErrorResponseReader;

/**
 * The status of the HTTP response an error carries, found by an ErrorResponseReaderInterface
 * (PSR-7 getResponse() by default).
 */
final class ResponseStatusRule implements RuleInterface
{
    private readonly ErrorResponseReaderInterface $reader;

    public function __construct(?ErrorResponseReaderInterface $reader = null)
    {
        $this->reader = $reader ?? new Psr7ErrorResponseReader();
    }

    public function apply(\Throwable $e): ?FailureMetadata
    {
        try {
            $response = $this->reader->read($e);
        } catch (\Throwable) {
            return null; // a reader that throws found nothing
        }
        if ($response === null || $response->statusCode < 100 || $response->statusCode > 599) {
            return null;
        }
        return new FailureMetadata($response->statusCode);
    }
}
