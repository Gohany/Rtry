<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

/**
 * The shape of Laravel's Illuminate\Http\Client\RequestException (checked against
 * illuminate/http 12.73.0): the response sits in an untyped public $response property with
 * status() and headers(), the exception code is the status, and the message carries the
 * response body. It has no getResponse(). Test-only.
 */
final class LaravelShapedFailure extends \Exception
{
    /** @var object */
    public $response;

    public function __construct(object $response, int $code, string $body)
    {
        parent::__construct("HTTP request returned status code {$code}:\n{$body}\n", $code);
        $this->response = $response;
    }

    /** @param array<string,list<string>> $headers PSR-7 style, as Laravel's headers() returns */
    public static function status(int $status, array $headers = [], string $body = ''): self
    {
        $response = new class ($status, $headers) {
            /** @param array<string,list<string>> $headers */
            public function __construct(private int $status, private array $headers)
            {
            }

            public function status(): int
            {
                return $this->status;
            }

            /** @return array<string,list<string>> */
            public function headers(): array
            {
                return $this->headers;
            }
        };
        return new self($response, $status, $body);
    }
}
