<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

/**
 * An SDK error that exposes the HTTP status through an accessor and leaves the exception code
 * at 0: Stripe's getHttpStatus(), the AWS SDK's getStatusCode(). Test-only.
 */
final class StatusAccessorFailure extends \RuntimeException
{
    public function __construct(private int $status, string $message, private string $accessor = 'getHttpStatus')
    {
        parent::__construct($message, 0);
    }

    public function getHttpStatus(): ?int
    {
        return $this->accessor === 'getHttpStatus' ? $this->status : null;
    }

    public function getStatusCode(): ?int
    {
        return $this->accessor === 'getStatusCode' ? $this->status : null;
    }
}
