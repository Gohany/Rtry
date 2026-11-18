<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

/**
 * Tags the classifier derives, and the SPEC 6.3 expansion of the on token "default".
 */
final class FailureToken
{
    public const DEFAULT = 'DEFAULT';
    public const FIVE_HUNDRED = '5XX';
    public const FOUR_HUNDRED = '4XX';
    public const ETIMEDOUT = 'ETIMEDOUT';
    public const NETWORK_ERROR = 'NETWORK_ERROR';
    public const ECONNRESET = 'ECONNRESET';
    public const ECONNREFUSED = 'ECONNREFUSED';
    public const DEADLOCK = 'DEADLOCK';
    public const RATE_LIMITED = 'RATE_LIMITED';

    /**
     * What on=default (and a spec without on) retries: 408, 429, 5xx and transport,
     * deadlock and rate-limit tags. Never another 4xx, never an error with no status or tag.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            '408',
            '429',
            self::FIVE_HUNDRED,
            self::ETIMEDOUT,
            self::ECONNRESET,
            self::ECONNREFUSED,
            self::NETWORK_ERROR,
            self::DEADLOCK,
            self::RATE_LIMITED,
        ];
    }
}
