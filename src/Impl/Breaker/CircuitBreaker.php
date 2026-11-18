<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Breaker;

use Gohany\Retry\CircuitBreakerInterface;
use Gohany\Retry\CircuitBreakerStoreInterface;
use Gohany\Retry\CircuitState;
use Gohany\Rtry\Impl\Clock;
use Psr\Clock\ClockInterface;

/**
 * A count-in-window circuit breaker per key (SPEC 5.17).
 *
 * Closed: allowed; $failureThreshold failures within $windowMs open it. Open: refused for
 * $openMs. Then half-open: exactly one caller (the probe) is allowed across every process
 * sharing the store; its success closes the circuit, its failure opens it again, and a probe
 * that never reports is replaced after another $openMs.
 *
 * The whole state of a key is one small JSON value behind the store's versioned
 * compare-and-set, so a clustered store needs no multi-key script.
 */
final class CircuitBreaker implements CircuitBreakerInterface
{
    private const MAX_CAS_TRIES = 50;

    public function __construct(
        private readonly CircuitBreakerStoreInterface $store,
        private readonly ClockInterface $clock,
        private readonly int $failureThreshold = 5,
        private readonly int $windowMs = 10_000,
        private readonly int $openMs = 30_000,
    ) {
        if ($failureThreshold < 1 || $windowMs < 1 || $openMs < 1) {
            throw new \InvalidArgumentException('failureThreshold, windowMs and openMs must be at least 1');
        }
    }

    public function allow(string $key): bool
    {
        $allowed = false;
        $this->update($key, function (array $s, int $now) use (&$allowed): ?array {
            $allowed = false;
            if ($s['s'] === CircuitState::Closed->value) {
                $allowed = true;
                return null;
            }
            // Open since o, or half-open with a probe out since p.
            $since = $s['s'] === CircuitState::Open->value ? $s['o'] : $s['p'];
            if ($now - $since < $this->openMs) {
                return null;
            }
            $allowed = true;
            return ['s' => CircuitState::HalfOpen->value, 'f' => [], 'o' => $s['o'], 'p' => $now];
        });
        return $allowed;
    }

    public function recordSuccess(string $key): void
    {
        $this->update($key, static fn (array $s): ?array => $s['s'] === CircuitState::HalfOpen->value
            ? self::closed()
            : null);
    }

    public function recordFailure(string $key): void
    {
        $this->update($key, function (array $s, int $now): ?array {
            if ($s['s'] === CircuitState::HalfOpen->value) {
                return ['s' => CircuitState::Open->value, 'f' => [], 'o' => $now, 'p' => 0];
            }
            if ($s['s'] === CircuitState::Open->value) {
                return null;
            }
            $failures = array_values(array_filter($s['f'], fn (int $t): bool => $now - $t <= $this->windowMs));
            $failures[] = $now;
            if (count($failures) >= $this->failureThreshold) {
                return ['s' => CircuitState::Open->value, 'f' => [], 'o' => $now, 'p' => 0];
            }
            return ['s' => CircuitState::Closed->value, 'f' => $failures, 'o' => 0, 'p' => 0];
        });
    }

    public function state(string $key): CircuitState
    {
        return CircuitState::from($this->read($key)[1]['s']);
    }

    /** @return array{s:string,f:list<int>,o:int,p:int} */
    private static function closed(): array
    {
        return ['s' => CircuitState::Closed->value, 'f' => [], 'o' => 0, 'p' => 0];
    }

    /** @return array{?int, array{s:string,f:list<int>,o:int,p:int}} */
    private function read(string $key): array
    {
        $row = $this->store->load($key);
        if ($row === null) {
            return [null, self::closed()];
        }
        $state = json_decode($row->value(), true, 4);
        if (!is_array($state) || !isset($state['s'], $state['f'], $state['o'], $state['p']) || CircuitState::tryFrom((string) $state['s']) === null) {
            // Unreadable state (another version, a corrupted value): start closed rather than fail every call.
            return [$row->version(), self::closed()];
        }
        return [$row->version(), $state];
    }

    /** @param callable(array{s:string,f:list<int>,o:int,p:int}, int): ?array $change null = no write */
    private function update(string $key, callable $change): void
    {
        for ($try = 0; $try < self::MAX_CAS_TRIES; $try++) {
            [$version, $state] = $this->read($key);
            $next = $change($state, Clock::nowMs($this->clock));
            if ($next === null) {
                return;
            }
            $ttl = $this->windowMs + 2 * $this->openMs;
            if ($this->store->compareAndSet($key, $version, json_encode($next, JSON_THROW_ON_ERROR), $ttl)) {
                return;
            }
        }
        throw new \RuntimeException('Circuit breaker state for ' . $key . ' kept changing; gave up after ' . self::MAX_CAS_TRIES . ' tries');
    }
}
