<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

use Gohany\Retry\CircuitBreakerInterface;
use Gohany\Retry\CircuitState;

/** A breaker that always allows and stays closed, and records what it was told. */
final class RecordingBreaker implements CircuitBreakerInterface
{
    /** @var list<'recordSuccess'|'recordFailure'> */
    public array $reports = [];

    public function allow(string $key): bool
    {
        return true;
    }

    public function recordSuccess(string $key): void
    {
        $this->reports[] = 'recordSuccess';
    }

    public function recordFailure(string $key): void
    {
        $this->reports[] = 'recordFailure';
    }

    public function state(string $key): CircuitState
    {
        return CircuitState::Closed;
    }
}
