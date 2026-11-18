<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

/**
 * The status and headers of the HTTP response a thrown error carries. Header names are
 * lower-cased; each value is a list of strings. A value that is not a string, a number or
 * Stringable (a nested list, say) is dropped.
 */
final class ErrorResponse
{
    /** @var array<string, list<string>> */
    public readonly array $headers;

    /** @param array<array-key, mixed> $headers any case; a value or a list */
    public function __construct(public readonly int $statusCode, array $headers = [])
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $key = strtolower((string) $name);
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_scalar($value) || $value instanceof \Stringable) {
                    $out[$key][] = (string) $value;
                }
            }
        }
        $this->headers = $out;
    }
}
