<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

/** @internal a PDOException with its code and errorInfo set, as PDO and Laravel set them. */
final class PdoShaped extends \PDOException
{
    /** @param array<int, int|string>|null $errorInfo */
    public static function make(string $message, int|string $code, ?array $errorInfo, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        $e->code = $code;
        $e->errorInfo = $errorInfo;
        return $e;
    }
}
