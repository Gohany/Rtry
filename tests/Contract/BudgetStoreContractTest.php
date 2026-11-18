<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Contract;

use Gohany\Retry\RetryBudgetStoreInterface;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RetryBudgetStoreContractTestCase;
use Gohany\Rtry\Impl\Budget\InMemoryRetryBudgetStore;

final class BudgetStoreContractTest extends RetryBudgetStoreContractTestCase
{
    protected function createStore(): RetryBudgetStoreInterface
    {
        return new InMemoryRetryBudgetStore(new FrozenClock());
    }

    public function testAnExpiredKeyStartsAtTheMaximumAgain(): void
    {
        $clock = new FrozenClock();
        $store = new InMemoryRetryBudgetStore($clock);
        $store->add('k', -4, 10, 1_000);

        $clock->advanceMs(999);
        self::assertSame(6.0, $store->add('k', 0, 10, 1_000));
        $clock->advanceMs(1_000);
        self::assertSame(10.0, $store->add('k', 0, 10, 1_000));
    }
}
