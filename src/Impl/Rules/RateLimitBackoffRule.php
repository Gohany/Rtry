<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Impl\Psr7ErrorResponseReader;

/**
 * Extracts backoff hints from HTTP response headers:
 * - Retry-After: seconds (delta) or HTTP-date (absolute)
 * - RateLimit-Reset / X-RateLimit-Reset: delta seconds below 10^9, otherwise epoch seconds or ms
 * - RateLimit-Reset-After / X-RateLimit-Reset-After: delta seconds
 *
 * The response comes from an ErrorResponseReaderInterface: PSR-7 getResponse() by default.
 *
 * Tags 'RATE_LIMITED' when a rate-limit header is observed on a 429 or a 503, or on a response
 * that is not an HTTP error. Many APIs send X-RateLimit-* on every response, so on any other
 * 4xx or 5xx the header is only a delay hint and the status alone decides.
 */
final class RateLimitBackoffRule implements RuleInterface
{
    private readonly ErrorResponseReaderInterface $reader;

    public function __construct(?ErrorResponseReaderInterface $reader = null)
    {
        $this->reader = $reader ?? new Psr7ErrorResponseReader();
    }

    /**
     * Extract backoff hints from common rate-limit headers.
     * Returns FailureMetadata with:
     *  - status (only if >= 400),
     *  - tags ['RATE_LIMITED'] on a 429, a 503 or a status below 400,
     *  - context patch (empty array here),
     *  - either minNextDelayMs OR notBeforeUnixMs,
     *  - echoed subset of headers that influenced the decision.
     */
    public function apply(\Throwable $e): ?FailureMetadataInterface
    {
        // 1) Find the response on the exception. A reader that throws found nothing.
        try {
            $response = $this->reader->read($e);
        } catch (\Throwable) {
            return null;
        }
        if ($response === null) {
            return null;
        }

        // 2) Normalize headers to a simple lowercase map: name => firstValueString.
        $headers = $this->normalizeHeaders($response->headers);
        if ($headers === []) {
            return null;
        }

        // 3) Compute backoff hints from the headers (Retry-After, RateLimit-Reset*, etc).
        $hints = $this->computeBackoffFromHeaders($headers);
        if ($hints === null) {
            return null; // No recognizable rate-limit hints.
        }
        $minNextDelayMs = $hints['minNextDelayMs'];
        $notBeforeMs = $hints['notBeforeUnixMs'];
        $usedKeys = $hints['usedKeys'];

        // 4) Decide whether to propagate HTTP status. (Only if >= 400.)
        $statusCode = $response->statusCode;
        $statusOut = ($statusCode >= 400) ? $statusCode : null;

        // 5) Build a slim header echo containing only keys that influenced the outcome.
        $headersOut = [];
        foreach ($usedKeys as $key) {
            if (isset($headers[$key])) {
                $headersOut[$key] = $headers[$key]; // already a single string
            }
        }

        // 6) Tag and return metadata. Any other HTTP error status is judged by itself alone.
        $tags = ($statusOut === null || $statusOut === 429 || $statusOut === 503) ? ['RATE_LIMITED'] : [];
        $ctxPatch = []; // keep empty unless you want to surface more context

        return new FailureMetadata(
            $statusOut,
            $tags,
            $ctxPatch,
            $minNextDelayMs,
            $notBeforeMs,
            $headersOut
        );
    }

    /**
     * Normalize headers to: lowercased-name => first string value.
     * Examples:
     *   ['Retry-After' => ['1']]        -> ['retry-after' => '1']
     *   ['X-RateLimit-Reset' => [1234]] -> ['x-ratelimit-reset' => '1234']
     */
    private function normalizeHeaders(array $psrHeaders): array
    {
        $out = [];
        foreach ($psrHeaders as $name => $values) {
            $lower = strtolower((string)$name);
            if (is_array($values) && $values !== []) {
                $first = (string)reset($values);
            } else {
                $first = is_scalar($values) ? (string)$values : '';
            }
            if ($first !== '') {
                $out[$lower] = trim($first);
            }
        }

        return $out;
    }

    /**
     * Look for common rate-limit headers and compute either:
     *   - 'minNextDelayMs' (a delta to wait), or
     *   - 'notBeforeUnixMs' (absolute UNIX epoch ms when to retry),
     * plus which header keys were used (for echoing back).
     *
     * Recognized (case-insensitive, examples of values):
     *   - retry-after: "2" (seconds) OR "Wed, 21 Oct 2015 07:28:00 GMT" (HTTP-date)
     *   - ratelimit-reset-after / x-ratelimit-reset-after: "5" (delta seconds)
     *   - ratelimit-reset / x-ratelimit-reset: "1717000000" (epoch seconds) or "1717000000123" (epoch ms)
     *   - x-ratelimit-reset-ms: "1717000000123" (epoch ms)
     */
    private function computeBackoffFromHeaders(array $headers): ?array
    {
        // 1) Retry-After (delta seconds OR HTTP-date)
        if (isset($headers['retry-after'])) {
            $raw = $headers['retry-after'];
            // numeric => seconds
            if ($this->isNumericLike($raw)) {
                $sec = (float)$raw;
                $ms = (int)max(0, round($sec * 1000.0));

                return ['minNextDelayMs' => $ms, 'notBeforeUnixMs' => null, 'usedKeys' => ['retry-after']];
            }
            // HTTP-date => absolute epoch ms
            $ts = strtotime($raw);
            if ($ts !== false) {
                return ['minNextDelayMs' => null, 'notBeforeUnixMs' => $ts * 1000, 'usedKeys' => ['retry-after']];
            }
            // Unrecognized value — ignore and continue to other headers.
        }

        // 2) *-Reset-After (delta seconds)
        foreach (['ratelimit-reset-after', 'x-ratelimit-reset-after'] as $k) {
            if (isset($headers[$k]) && $this->isNumericLike($headers[$k])) {
                $sec = (float)$headers[$k];
                $ms = (int)max(0, round($sec * 1000.0));

                return ['minNextDelayMs' => $ms, 'notBeforeUnixMs' => null, 'usedKeys' => [$k]];
            }
        }

        // 3) *-Reset (absolute timestamp)
        foreach (['x-ratelimit-reset-ms'] as $k) { // explicit milliseconds
            if (isset($headers[$k]) && $this->isNumericLike($headers[$k])) {
                $ms = (int)$headers[$k];
                if ($ms > 0) {
                    return ['minNextDelayMs' => null, 'notBeforeUnixMs' => $ms, 'usedKeys' => [$k]];
                }
            }
        }
        foreach (['ratelimit-reset', 'x-ratelimit-reset'] as $k) {
            if (isset($headers[$k]) && $this->isNumericLike($headers[$k])) {
                $num = (float)$headers[$k];
                if ($num <= 0) {
                    continue;
                }
                // The IETF RateLimit fields send delta seconds; older X-RateLimit-Reset headers
                // send an epoch time in seconds or ms. Below 10^9 (2001 as an epoch) is a delta.
                if ($num < 1e9) {
                    return ['minNextDelayMs' => (int)round($num * 1000.0), 'notBeforeUnixMs' => null, 'usedKeys' => [$k]];
                }
                $ms = ($num >= 1e12) ? (int)$num : (int)round($num * 1000.0);

                return ['minNextDelayMs' => null, 'notBeforeUnixMs' => $ms, 'usedKeys' => [$k]];
            }
        }

        // Nothing recognized
        return null;
    }

    /** Numeric-like strings: "2", "2.5", "002". */
    private function isNumericLike(string $s): bool
    {
        // ctype_digit covers integers; fallback to is_numeric for floats
        return $s !== '' && (ctype_digit($s) || is_numeric($s));
    }
}