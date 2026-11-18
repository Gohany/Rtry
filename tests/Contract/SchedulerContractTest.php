<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Contract;

use Gohany\Retry\RetryPolicyFactoryInterface;
use Gohany\Retry\RetrySchedulerInterface;
use Gohany\Retry\Testing\RetrySchedulerContractTestCase;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

final class SchedulerContractTest extends RetrySchedulerContractTestCase
{
    protected function createScheduler(ClockInterface $clock, Randomizer $random): RetrySchedulerInterface
    {
        return new RtryScheduler($clock, $random);
    }

    protected function createPolicyFactory(): RetryPolicyFactoryInterface
    {
        return new RtryPolicyFactory();
    }
}
