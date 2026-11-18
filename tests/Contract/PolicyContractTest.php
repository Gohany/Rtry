<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Contract;

use Gohany\Retry\RetryPolicyFactoryInterface;
use Gohany\Retry\Testing\RetryPolicyContractTestCase;
use Gohany\Rtry\Impl\RtryPolicyFactory;

final class PolicyContractTest extends RetryPolicyContractTestCase
{
    protected function createPolicyFactory(): RetryPolicyFactoryInterface
    {
        return new RtryPolicyFactory();
    }
}
