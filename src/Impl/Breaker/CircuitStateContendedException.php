<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Breaker;

/**
 * Thrown by CircuitBreaker when its compare-and-set on a key's state kept losing to other
 * writers and it gave up. Retry treats it as any failing guard: the breaker fails open.
 *
 * The message is the same for every key and never contains it: breaker keys can carry tenant
 * or customer ids, and callers log exception messages. Read the key from key() and put it in
 * log context (as gohany/retry's CircuitOpenException).
 */
final class CircuitStateContendedException extends \RuntimeException
{
    public function __construct(
        private readonly string $key,
        int $tries,
    ) {
        parent::__construct('Circuit breaker state kept changing; gave up after ' . $tries . ' tries');
    }

    public function key(): string
    {
        return $this->key;
    }
}
