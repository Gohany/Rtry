<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every ```php block in README.md runs, and every rtry: string in it parses. The blocks get
 * the stand-ins they refer to ($client, $url, $job, $now, $error, $logger).
 */
final class ReadmeExamplesTest extends TestCase
{
    private static function readme(): string
    {
        return (string) file_get_contents(__DIR__ . '/../README.md');
    }

    /** @return iterable<string,array{string}> */
    public static function phpBlocks(): iterable
    {
        preg_match_all('~```php\n(.*?)```~s', self::readme(), $m);
        foreach ($m[1] as $i => $code) {
            $first = strtok(trim($code), "\n");
            yield sprintf('block %d: %s', $i + 1, $first) => [$code];
        }
    }

    #[DataProvider('phpBlocks')]
    public function testPhpBlockRuns(string $code): void
    {
        $vars = [
            'client' => new class {
                public int $calls = 0;

                public function get(string $url): string
                {
                    $this->calls++;
                    return 'ok';
                }
            },
            'url' => 'https://api.example.test/classes',
            'job' => (object) ['attempts' => 2, 'nextAttemptAt' => null, 'failedBecause' => null],
            'now' => new \DateTimeImmutable('2026-11-17 12:00:00'),
            'error' => HttpFailure::status(503),
            'logger' => new NullLogger(),
        ];

        $run = static function (string $__code, array $__vars): void {
            extract($__vars);
            eval($__code);
        };
        $run($code, $vars);

        self::assertGreaterThanOrEqual(0, $vars['client']->calls);
        if (str_contains($code, '$job->')) {
            self::assertNotNull($vars['job']->nextAttemptAt ?? $vars['job']->failedBecause, 'the durable example stores a decision');
        }
    }

    public function testReadmeHasExamples(): void
    {
        self::assertGreaterThanOrEqual(6, iterator_count(self::phpBlocks()));
    }

    public function testEveryRtryStringParses(): void
    {
        preg_match_all('~rtry:[A-Za-z0-9=;%@.,*&()_-]+~', self::readme(), $m);
        $specs = array_values(array_unique(array_filter($m[0], static fn (string $s) => str_contains($s, '='))));
        self::assertGreaterThanOrEqual(8, count($specs));

        $factory = new RtryPolicyFactory();
        foreach ($specs as $spec) {
            $policy = $factory->fromSpec($spec);
            self::assertSame($policy->canonicalSpec(), $factory->fromSpec($policy->canonicalSpec())->canonicalSpec(), $spec);
        }
    }

    public function testTheQuickStartScheduleIsWhatTheReadmeSays(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=5;d=200;m=exp;b=2;cap=8s;j=100%@full;on=5xx,429,ETIMEDOUT');
        self::assertSame([200, 400, 800, 1600], array_map($policy->nominalDelayMs(...), [2, 3, 4, 5]));
    }
}
