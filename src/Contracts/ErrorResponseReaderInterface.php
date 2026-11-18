<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

/**
 * Finds the HTTP response a thrown error carries, as a status and headers. The default reads
 * a PSR-7 response from getResponse(); implement this for a client that is not PSR-7
 * (Symfony HttpClient, say) so its status and Retry-After reach the classifier.
 */
interface ErrorResponseReaderInterface
{
    /** The response on $e, or null when it carries none this reader understands. Never throws. */
    public function read(\Throwable $e): ?ErrorResponse;
}
