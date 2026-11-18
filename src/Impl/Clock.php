<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Psr\Clock\ClockInterface;

/**
 * @internal milliseconds since the epoch from a PSR-20 clock
 */
final class Clock
{
    public static function nowMs(ClockInterface $clock): int
    {
        return (int) $clock->now()->format('Uv');
    }
}
