<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

/**
 * Turns a server's wait (Retry-After, RateLimit-Reset) into integer milliseconds without a
 * float-to-int cast that warns, wraps or throws. Anything beyond the bounds is pinned to them:
 * the scheduler then gives up on it (RtryScheduler::MAX_HEADER_WAIT_MS) instead of sleeping.
 *
 * @internal
 */
final class HeaderWaits
{
    /** 100 years: a relative wait longer than this is pinned here. */
    public const MAX_DELTA_MS = 3_153_600_000_000;

    /** 9999-12-31T23:59:59.999Z: an absolute time later than this is pinned here. */
    public const MAX_EPOCH_MS = 253_402_300_799_999;

    /** Seconds (as a header sends them) to a wait in ms; 0 for a negative or NaN value. */
    public static function secondsToMs(float $seconds): int
    {
        if (is_nan($seconds) || $seconds <= 0.0) {
            return 0;
        }
        $ms = $seconds * 1000.0;
        return $ms >= self::MAX_DELTA_MS ? self::MAX_DELTA_MS : (int) round($ms);
    }

    /** An epoch time in ms (float) to an int; null for zero, a negative value or NaN. */
    public static function epochMs(float $ms): ?int
    {
        if (is_nan($ms) || $ms <= 0.0) {
            return null;
        }
        return $ms >= self::MAX_EPOCH_MS ? self::MAX_EPOCH_MS : (int) round($ms);
    }

    public static function clampDelta(int $ms): int
    {
        return max(0, min($ms, self::MAX_DELTA_MS));
    }

    public static function clampEpoch(int $ms): int
    {
        return max(0, min($ms, self::MAX_EPOCH_MS));
    }
}
