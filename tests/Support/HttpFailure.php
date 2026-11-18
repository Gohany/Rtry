<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * An HTTP client error carrying its response, the shape Guzzle and Symfony HttpClient use.
 */
final class HttpFailure extends \RuntimeException
{
    public function __construct(private ResponseInterface $response, ?string $message = null)
    {
        parent::__construct($message ?? 'HTTP ' . $response->getStatusCode());
    }

    /** @param array<string,string> $headers */
    public static function status(int $status, array $headers = [], ?string $message = null): self
    {
        return new self(new FakeResponse($status, $headers), $message);
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
