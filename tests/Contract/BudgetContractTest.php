<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Contract;

use Gohany\Retry\RetryBudgetInterface;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RetryBudgetContractTestCase;
use Gohany\Rtry\Impl\Budget\InMemoryRetryBudgetStore;
use Gohany\Rtry\Impl\Budget\TokenBucketRetryBudget;

final class BudgetContractTest extends RetryBudgetContractTestCase
{
    protected function createBudget(float $maxTokens, float $tokenRatio): RetryBudgetInterface
    {
        return new TokenBucketRetryBudget(new InMemoryRetryBudgetStore(new FrozenClock()), $maxTokens, $tokenRatio);
    }
}
