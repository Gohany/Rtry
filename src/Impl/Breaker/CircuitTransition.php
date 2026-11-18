<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Breaker;

use Gohany\Retry\CircuitState;

/**
 * One change of a circuit's state, as passed to CircuitBreaker's onTransition callback:
 * closed->open, open->half_open, half_open->closed or half_open->open.
 */
final class CircuitTransition
{
    public function __construct(
        public readonly string $key,
        public readonly CircuitState $from,
        public readonly CircuitState $to,
        /** When it happened, epoch milliseconds from the breaker's clock. */
        public readonly int $atUnixMs,
    ) {
    }

    public function at(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', intdiv($this->atUnixMs, 1000), $this->atUnixMs % 1000))
            ?: throw new \LogicException('unrepresentable time ' . $this->atUnixMs);
    }
}
