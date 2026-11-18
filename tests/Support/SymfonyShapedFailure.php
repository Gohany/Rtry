<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

/**
 * Symfony HttpClient's shape without the dependency: the exception code is the status (unless
 * $code says otherwise), and
 * getResponse() returns a response that is not PSR-7, whose getHeaders() throws on a 4xx/5xx
 * unless it is passed false. Test-only.
 */
final class SymfonyShapedFailure extends \RuntimeException
{
    /** @param array<string,list<string>> $headers lower-cased names, as Symfony returns them */
    public function __construct(private int $status, private array $headers = [], ?string $url = null, ?int $code = null, ?string $message = null)
    {
        parent::__construct(
            $message ?? sprintf('HTTP/2 %d returned for "%s".', $status, $url ?? 'https://api.example.test/v1/things'),
            $code ?? $status,
        );
    }

    public function getResponse(): object
    {
        $status = $this->status;
        $headers = $this->headers;
        return new class ($status, $headers) {
            /** @param array<string,list<string>> $headers */
            public function __construct(private int $status, private array $headers)
            {
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            /** @return array<string,list<string>> */
            public function getHeaders(bool $throw = true): array
            {
                if ($throw && $this->status >= 300) {
                    throw new \LogicException('getHeaders() on an error response throws unless passed false');
                }
                return $this->headers;
            }
        };
    }
}
