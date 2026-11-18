<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Contract;

use Gohany\Retry\RetryerInterface;
use Gohany\Retry\RetryPolicyFactoryInterface;
use Gohany\Retry\SleeperInterface;
use Gohany\Retry\Testing\RetryerContractTestCase;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

final class RetryerContractTest extends RetryerContractTestCase
{
    protected function createRetryer(SleeperInterface $sleeper, ClockInterface $clock, Randomizer $random): RetryerInterface
    {
        return new Retry($clock, $sleeper, null, $random);
    }

    protected function createPolicyFactory(): RetryPolicyFactoryInterface
    {
        return new RtryPolicyFactory();
    }
}
