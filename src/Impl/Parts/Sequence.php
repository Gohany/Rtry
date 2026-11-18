<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Retry\SequenceInterface;
use Gohany\Rtry\Impl\Duration;

/**
 * seq=<dur>[,<dur>...][*]: explicit delays, the first being the delay before attempt #2.
 * A trailing * repeats the last delay. Accepts seq=(50,100,1.5s*) and seq=50,100,1.5s,*.
 */
final class Sequence implements SequenceInterface
{
    public const KEY = 'seq';

    /** @param list<int> $delaysMs */
    public function __construct(
        private readonly array $delaysMs,
        private readonly bool $repeatLast,
    ) {
        if ($delaysMs === []) {
            throw new \InvalidArgumentException('seq needs at least one delay');
        }
        foreach ($delaysMs as $delay) {
            if (!is_int($delay) || $delay < 0) {
                throw new \InvalidArgumentException('seq delays must be non-negative integers');
            }
        }
    }

    /** Parses the value of seq=. */
    public static function make(string $value): self
    {
        $v = trim($value);
        if (str_starts_with($v, '(') && str_ends_with($v, ')')) {
            $v = trim(substr($v, 1, -1));
        }
        if ($v === '') {
            throw new \InvalidArgumentException('seq cannot be empty');
        }

        $parts = array_map('trim', explode(',', $v));
        $repeat = false;
        $last = count($parts) - 1;
        if ($parts[$last] === '*') {
            $repeat = true;
            array_pop($parts);
        } elseif (str_ends_with($parts[$last], '*')) {
            $repeat = true;
            $parts[$last] = rtrim(substr($parts[$last], 0, -1));
        }
        if ($parts === []) {
            throw new \InvalidArgumentException('seq cannot be only "*"');
        }

        $delays = [];
        foreach ($parts as $part) {
            if ($part === '') {
                throw new \InvalidArgumentException('Empty element in seq: ' . $value);
            }
            $delays[] = Duration::parseDurationMs($part);
        }

        return new self($delays, $repeat);
    }

    public function delaysMs(): array
    {
        return $this->delaysMs;
    }

    public function isRepeatLastDelay(): bool
    {
        return $this->repeatLast;
    }

    public function delayMsAt(int $attemptNumber): ?int
    {
        if ($attemptNumber < 2) {
            throw new \InvalidArgumentException('There is no delay before attempt ' . $attemptNumber);
        }
        $index = $attemptNumber - 2;
        if ($index < count($this->delaysMs)) {
            return $this->delaysMs[$index];
        }
        return $this->repeatLast ? $this->delaysMs[count($this->delaysMs) - 1] : null;
    }

    /** Canonical value of seq= (SPEC 6.4), without the key. */
    public function canonical(): string
    {
        return implode(',', $this->delaysMs) . ($this->repeatLast ? '*' : '');
    }
}
