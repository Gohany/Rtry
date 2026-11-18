<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The smallest PSR-7 response the rules read: a status and headers. Test-only.
 */
final class FakeResponse implements ResponseInterface
{
    /** @param array<string,string|list<string>> $headers */
    public function __construct(private int $status, private array $headers = [])
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $name => $value) {
            $out[$name] = (array) $value;
        }
        return $out;
    }

    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== [];
    }

    public function getHeader(string $name): array
    {
        foreach ($this->getHeaders() as $n => $values) {
            if (strcasecmp($n, $name) === 0) {
                return $values;
            }
        }
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        return $this->withHeader($name, $value);
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        throw new \LogicException('FakeResponse has no body');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->status = $code;
        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}
