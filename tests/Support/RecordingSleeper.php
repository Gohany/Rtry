<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

use Gohany\Rtry\Contracts\SleeperInterface;

/**
 * Records every sleep Retry asks for instead of sleeping.
 */
final class RecordingSleeper implements SleeperInterface
{
    /** @var list<int> */
    private array $sleeps = [];

    public function sleepMs(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }

    /** @return list<int> */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
