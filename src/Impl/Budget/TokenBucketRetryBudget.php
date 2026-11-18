<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Budget;

use Gohany\Retry\RetryBudgetInterface;
use Gohany\Retry\RetryBudgetStoreInterface;

/**
 * gRPC-style retry throttling per key (SPEC 5.15): a key starts with $maxTokens; each retry
 * wanted costs 1; each success earns $tokenRatio; a retry is allowed only while the tokens
 * left are above $maxTokens / 2. With the defaults (10, 0.1) a failing service gets at most
 * about one retry per ten successful calls once the first few are spent.
 *
 * Share the store between processes (Foundation backs it with Valkey) to cap retries across
 * every worker; InMemoryRetryBudgetStore caps one process.
 */
final class TokenBucketRetryBudget implements RetryBudgetInterface
{
    public function __construct(
        private readonly RetryBudgetStoreInterface $store,
        private readonly float $maxTokens = 10.0,
        private readonly float $tokenRatio = 0.1,
        private readonly int $ttlMs = 600_000,
    ) {
        if (!($maxTokens > 0.0) || !is_finite($maxTokens)) {
            throw new \InvalidArgumentException('maxTokens must be above 0');
        }
        if (!($tokenRatio > 0.0) || !is_finite($tokenRatio)) {
            throw new \InvalidArgumentException('tokenRatio must be above 0');
        }
        if ($ttlMs < 1) {
            throw new \InvalidArgumentException('ttlMs must be at least 1');
        }
    }

    public function tryAcquire(string $key): bool
    {
        return $this->store->add($key, -1.0, $this->maxTokens, $this->ttlMs) > $this->maxTokens / 2;
    }

    public function recordSuccess(string $key): void
    {
        $this->store->add($key, $this->tokenRatio, $this->maxTokens, $this->ttlMs);
    }

    public function tokens(string $key): float
    {
        return $this->store->add($key, 0.0, $this->maxTokens, $this->ttlMs);
    }
}
