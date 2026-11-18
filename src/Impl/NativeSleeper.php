<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\SleeperInterface;

final class NativeSleeper implements SleeperInterface
{
    public function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
