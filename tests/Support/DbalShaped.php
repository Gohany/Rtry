<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

/** @internal Doctrine DBAL's driver exception shape. */
final class DbalShaped extends \RuntimeException
{
    public function __construct(string $message, int $code, private ?string $sqlState, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getSQLState(): ?string
    {
        return $this->sqlState;
    }
}
