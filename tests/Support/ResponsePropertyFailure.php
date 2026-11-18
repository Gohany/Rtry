<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

/**
 * A client error that keeps its response in a public property and leaves the exception code
 * at 0, so nothing but a reader can see its status (Laravel's RequestException keeps
 * $response; its code is the status, this one's is not). Test-only.
 */
final class ResponsePropertyFailure extends \RuntimeException
{
    public function __construct(public readonly object $response, string $message)
    {
        parent::__construct($message, 0);
    }

    /** @param array<string,list<string>> $headers */
    public static function status(int $status, array $headers = [], string $message = 'HTTP request returned an error'): self
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
        return new self($response, $message);
    }
}
