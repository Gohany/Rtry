<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * An HTTP client error carrying its response, the shape Guzzle and Symfony HttpClient use.
 */
final class HttpFailure extends \RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(private ResponseInterface $response)
    {
        parent::__construct('HTTP ' . $response->getStatusCode());
    }

    /** @param array<string,string> $headers */
    public static function status(int $status, array $headers = []): self
    {
        return new self(new FakeResponse($status, $headers));
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
