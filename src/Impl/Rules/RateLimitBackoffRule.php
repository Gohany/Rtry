<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Impl\ErrorResponseLocator;
use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Impl\FailureToken;
use Gohany\Rtry\Impl\HeaderWaits;

/**
 * Turns a server's wait into a delay hint, from the response an error carries (found by an
 * ErrorResponseLocator, wherever it sits on the error):
 *
 * - Retry-After, on any response: delay seconds, or an HTTP date (IMF-fixdate, RFC 850,
 *   asctime, or "Day, DD Mon YYYY HH:MM:SS" with a zone). Words such as "tomorrow" are not
 *   dates here.
 * - On a 429 or a 503 only: RateLimit-Reset-After / X-RateLimit-Reset-After (delta seconds),
 *   X-RateLimit-Reset-Ms (epoch ms), RateLimit-Reset / X-RateLimit-Reset (delta seconds below
 *   10^9, otherwise epoch seconds, or epoch ms from 10^12). Many APIs send these on every
 *   response, so on any other status they are not a wait and not a sign of rate limiting.
 *
 * Tags RATE_LIMITED on a 429 or a 503 that carries one of these headers, never elsewhere.
 * Values are parsed defensively: a huge, negative, NaN or malformed value never warns or
 * throws; a huge one is pinned to HeaderWaits' bounds and the scheduler gives up on it.
 */
final class RateLimitBackoffRule implements RuleInterface
{
    private const DATE_FORMATS = [
        'D, d M Y H:i:s \G\M\T', // IMF-fixdate (RFC 9110)
        'l, d-M-y H:i:s \G\M\T', // RFC 850
        'D M j H:i:s Y',         // asctime
    ];

    private readonly ErrorResponseLocator $locator;

    public function __construct(?ErrorResponseReaderInterface $reader = null)
    {
        $this->locator = ErrorResponseLocator::wrap($reader);
    }

    public function apply(\Throwable $e): ?FailureMetadataInterface
    {
        $response = $this->locator->read($e);
        if ($response === null) {
            return null;
        }
        $headers = self::firstValues($response->headers);
        if ($headers === []) {
            return null;
        }

        $status = $response->statusCode;
        $rateLimited = $status === 429 || $status === 503;
        $hint = self::retryAfter($headers) ?? ($rateLimited ? self::resetHeaders($headers) : null);
        if ($hint === null) {
            return null;
        }
        [$minNextDelayMs, $notBeforeUnixMs, $key] = $hint;

        return new FailureMetadata(
            $status >= 400 && $status <= 599 ? $status : null,
            $rateLimited ? [FailureToken::RATE_LIMITED] : [],
            [],
            $minNextDelayMs,
            $notBeforeUnixMs,
            [$key => [$headers[$key]]],
            fromResponse: true,
        );
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, string> lower-cased name => first non-empty value, trimmed
     */
    private static function firstValues(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $first = trim((string) ($values[0] ?? ''));
            if ($first !== '') {
                $out[$name] = $first;
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $headers
     * @return array{?int, ?int, string}|null [minNextDelayMs, notBeforeUnixMs, header used]
     */
    private static function retryAfter(array $headers): ?array
    {
        $raw = $headers['retry-after'] ?? null;
        if ($raw === null) {
            return null;
        }
        if (is_numeric($raw)) {
            return [HeaderWaits::secondsToMs((float) $raw), null, 'retry-after'];
        }
        $at = self::httpDateMs($raw);
        return $at === null ? null : [null, $at, 'retry-after'];
    }

    /**
     * @param array<string, string> $headers
     * @return array{?int, ?int, string}|null
     */
    private static function resetHeaders(array $headers): ?array
    {
        foreach (['ratelimit-reset-after', 'x-ratelimit-reset-after'] as $k) {
            if (isset($headers[$k]) && is_numeric($headers[$k])) {
                return [HeaderWaits::secondsToMs((float) $headers[$k]), null, $k];
            }
        }

        $k = 'x-ratelimit-reset-ms';
        if (isset($headers[$k]) && is_numeric($headers[$k])) {
            $at = HeaderWaits::epochMs((float) $headers[$k]);
            if ($at !== null) {
                return [null, $at, $k];
            }
        }

        foreach (['ratelimit-reset', 'x-ratelimit-reset'] as $k) {
            if (!isset($headers[$k]) || !is_numeric($headers[$k])) {
                continue;
            }
            $num = (float) $headers[$k];
            if (is_nan($num) || $num <= 0.0) {
                continue;
            }
            // The IETF RateLimit fields send delta seconds; older X-RateLimit-Reset headers
            // send an epoch time in seconds or ms. Below 10^9 (2001 as an epoch) is a delta.
            if ($num < 1e9) {
                return [HeaderWaits::secondsToMs($num), null, $k];
            }
            return [null, HeaderWaits::epochMs($num >= 1e12 ? $num : $num * 1000.0), $k];
        }

        return null;
    }

    /** An HTTP date as epoch ms, or null when $raw is not one. */
    private static function httpDateMs(string $raw): ?int
    {
        $utc = new \DateTimeZone('UTC');
        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $raw, $utc);
            if ($date !== false) {
                return HeaderWaits::epochMs($date->getTimestamp() * 1000.0);
            }
        }
        // 2.x read any strtotime() string; keep the date-shaped ones ("... +0000", "... UTC").
        if (preg_match('~^[A-Za-z]{3},\s+\d{1,2}\s+[A-Za-z]{3}\s+\d{4}\s+\d{2}:\d{2}:\d{2}(\s+[A-Za-z0-9+:-]{1,6})?$~', $raw)) {
            $ts = strtotime($raw);
            if ($ts !== false) {
                return HeaderWaits::epochMs($ts * 1000.0);
            }
        }
        return null;
    }
}
