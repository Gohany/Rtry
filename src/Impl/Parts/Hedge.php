<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Retry\HedgeInterface;
use Gohany\Rtry\Impl\Duration;

/**
 * h=<lanes>@<stagger>[&<0|1>]. A hint only: Rtry runs attempts one at a time and does not
 * hedge (SPEC 2). Callers that can run concurrent requests may read it.
 */
final class Hedge implements HedgeInterface
{
    public const KEY = 'h';

    public function __construct(
        private readonly int $lanes,
        private readonly int $staggerDelayMs,
        private readonly int $cancelPolicy = self::CANCEL_ON_FIRST_SUCCESS,
    ) {
        if ($lanes < 1) {
            throw new \InvalidArgumentException('Hedge lanes must be at least 1: ' . $lanes);
        }
        if ($staggerDelayMs < 0) {
            throw new \InvalidArgumentException('Hedge stagger cannot be negative');
        }
        if (!in_array($cancelPolicy, [self::CANCEL_ON_FIRST_SUCCESS, self::CANCEL_ON_FIRST_COMPLETION], true)) {
            throw new \InvalidArgumentException('Hedge cancel policy must be 0 or 1: ' . $cancelPolicy);
        }
    }

    /** Parses the value of h=. */
    public static function make(string $value): self
    {
        if (!preg_match('~^\s*([0-9]+)\s*@\s*([^&]+?)\s*(?:&\s*([0-9]+)\s*)?$~', $value, $m)) {
            throw new \InvalidArgumentException('Invalid hedge, expected <lanes>@<stagger>[&<0|1>]: ' . $value);
        }
        return new self((int) $m[1], Duration::parseDurationMs($m[2]), isset($m[3]) ? (int) $m[3] : self::CANCEL_ON_FIRST_SUCCESS);
    }

    public function lanes(): int
    {
        return $this->lanes;
    }

    public function staggerDelayMs(): int
    {
        return $this->staggerDelayMs;
    }

    public function cancelPolicy(): int
    {
        return $this->cancelPolicy;
    }

    /** Canonical value of h= (SPEC 6.4), without the key. */
    public function canonical(): string
    {
        return $this->lanes . '@' . $this->staggerDelayMs
            . ($this->cancelPolicy === self::CANCEL_ON_FIRST_COMPLETION ? '&1' : '');
    }
}
