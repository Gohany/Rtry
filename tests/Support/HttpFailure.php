<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * An HTTP client error: the status is the exception code, and an optional PSR-7 response
 * is reachable through getResponse(), the shape RateLimitBackoffRule reads headers from.
 */
final class HttpFailure extends \RuntimeException
{
    private ?ResponseInterface $response;

    public function __construct(int $status, ?ResponseInterface $response = null)
    {
        parent::__construct('HTTP ' . $status, $status);
        $this->response = $response;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
