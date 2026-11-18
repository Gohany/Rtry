<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Retry\JitterInterface;
use Gohany\Rtry\Impl\Duration;
use Random\Randomizer;

/**
 * j=<window>[@full|pm|none] (SPEC 6.2). The window is a duration or a percent of the delay.
 *
 * full: a uniform integer in [max(0, D - w), D]. pm: [max(0, D - w), D + w]. none: D.
 * Exactly one Randomizer::getInt() draw when the bounds differ, none otherwise.
 */
final class Jitter implements JitterInterface
{
    public const KEY = 'j';

    private function __construct(
        private readonly string $mode,
        private readonly ?float $percent,
        private readonly ?int $windowMs,
    ) {
    }

    /** No j: classic full jitter, [0, D]. */
    public static function default(): self
    {
        return new self(self::MODE_FULL, 100.0, null);
    }

    public static function none(): self
    {
        return new self(self::MODE_NONE, null, null);
    }

    public static function percentOf(float $percent, string $mode = self::MODE_FULL): self
    {
        if (!is_finite($percent) || $percent < 0.0 || $percent > 100.0) {
            throw new \InvalidArgumentException('Jitter percent must be between 0 and 100: ' . $percent);
        }
        return $percent == 0.0 ? self::none() : new self(self::parseMode($mode), $percent, null);
    }

    public static function window(int $windowMs, string $mode = self::MODE_FULL): self
    {
        if ($windowMs < 0) {
            throw new \InvalidArgumentException('Jitter window cannot be negative: ' . $windowMs);
        }
        return $windowMs === 0 ? self::none() : new self(self::parseMode($mode), null, $windowMs);
    }

    /** Parses the value of j=. */
    public static function make(string $value): self
    {
        $v = strtolower(trim($value));
        if ($v === 'none') {
            return self::none();
        }

        $mode = self::MODE_FULL;
        $at = strpos($v, '@');
        if ($at !== false) {
            $mode = trim(substr($v, $at + 1));
            $v = trim(substr($v, 0, $at));
        }
        $mode = self::parseMode($mode);
        if ($mode === self::MODE_NONE) {
            return self::none();
        }

        if (str_ends_with($v, '%')) {
            $number = substr($v, 0, -1);
            if (!preg_match('~^[0-9]+(?:\.[0-9]+)?$~', $number)) {
                throw new \InvalidArgumentException('Invalid jitter percent: ' . $value);
            }
            return self::percentOf((float) $number, $mode);
        }

        return self::window(Duration::parseDurationMs($v), $mode);
    }

    private static function parseMode(string $mode): string
    {
        $mode = strtolower($mode);
        if (!in_array($mode, [self::MODE_FULL, self::MODE_PM, self::MODE_NONE], true)) {
            throw new \InvalidArgumentException('Invalid jitter mode "' . $mode . '"; expected full, pm or none');
        }
        return $mode;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function percent(): ?float
    {
        return $this->percent;
    }

    public function windowMs(): ?int
    {
        return $this->windowMs;
    }

    public function apply(int $nominalDelayMs, Randomizer $random): int
    {
        $delay = max(0, $nominalDelayMs);
        if ($this->mode === self::MODE_NONE) {
            return $delay;
        }

        $window = $this->percent !== null
            ? (int) round($this->percent / 100.0 * $delay)
            : (int) $this->windowMs;

        $low = max(0, $delay - $window);
        $high = $this->mode === self::MODE_PM ? $delay + $window : $delay;

        return $low < $high ? $random->getInt($low, $high) : $low;
    }

    /** Canonical value of j= (SPEC 6.4), without the key. */
    public function canonical(): string
    {
        if ($this->mode === self::MODE_NONE) {
            return 'none';
        }
        $amount = $this->percent !== null ? Duration::formatNumber($this->percent) . '%' : (string) $this->windowMs;
        return $amount . '@' . $this->mode;
    }
}
