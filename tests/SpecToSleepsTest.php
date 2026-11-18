<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Rtry\Impl\Deciders\AlwaysRetryDecider;
use Gohany\Rtry\Impl\Deciders\OnTokensDecider;
use Gohany\Rtry\Impl\FailureToken;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * End to end: parse a real rtry: string, run Retry::try() against an operation that fails,
 * and record the sleeps Retry actually asked for. Each test names the 1.x bug it pins:
 *
 *  1. exponential backoff was keyed off sa= instead of d=;
 *  2. linear mode read an undefined property, so every delay was 0;
 *  3. no cap= meant a cap of 0, which clamped every delay to 0;
 *  5. full jitter drew from [0, window] instead of [delay - window, delay];
 *  6. on= was discarded, so every failure retried whatever the spec said.
 */
final class SpecToSleepsTest extends TestCase
{
    /** Jittered tests repeat this many times; every run must stay inside the bounds. */
    private const RUNS = 40;

    /** @var list<int> attempt numbers the last operation saw */
    private array $seen = [];

    /**
     * An operation that throws $errors[n] (or $errors['*']) on attempt n, and returns 'ok'
     * once there is no error for the attempt.
     *
     * @param array<int|string,\Throwable> $errors
     */
    private function failing(array $errors): callable
    {
        $this->seen = [];
        return function (AttemptContextInterface $attempt) use ($errors) {
            $n = $attempt->attemptNumber();
            $this->seen[] = $n;
            $error = $errors[$n] ?? $errors['*'] ?? null;
            if ($error !== null) {
                throw $error;
            }
            return 'ok';
        };
    }

    /**
     * Runs $operation under $spec and returns [result or caught error, sleeps].
     *
     * @return array{0: mixed, 1: list<int>}
     */
    private function runSpec(string $spec, callable $operation, ?RetryDeciderInterface $decider = null): array
    {
        $sleeper = new RecordingSleeper();
        $policy = (new RtryPolicyFactory())->fromSpec($spec, $decider);
        try {
            $value = (new Retry(null, $sleeper))->try($operation, $policy)->result();
        } catch (\Throwable $e) {
            $value = $e;
        }
        return [$value, $sleeper->sleeps()];
    }

    /**
     * @param list<array{0:int,1:int}> $bounds
     * @param list<int> $sleeps
     */
    private static function assertWithin(array $bounds, array $sleeps, string $spec): void
    {
        self::assertCount(count($bounds), $sleeps, $spec);
        foreach ($bounds as $i => [$low, $high]) {
            self::assertGreaterThanOrEqual($low, $sleeps[$i], $spec . ' sleep ' . ($i + 1));
            self::assertLessThanOrEqual($high, $sleeps[$i], $spec . ' sleep ' . ($i + 1));
        }
    }

    // ----- Bug 1: exponential backoff grows from d=, not sa= -----

    public function testExponentialGrowsFromD(): void
    {
        [$value, $sleeps] = $this->runSpec('rtry:a=5;d=200;m=exp;b=2;j=0', $this->failing([1 => new HttpFailure(503), 2 => new HttpFailure(503), 3 => new HttpFailure(503), 4 => new HttpFailure(503)]));

        self::assertSame('ok', $value);
        self::assertSame([1, 2, 3, 4, 5], $this->seen);
        self::assertSame([200, 400, 800, 1600], $sleeps);
    }

    public function testStartAfterWaitsOnceAndDoesNotScaleTheBackoff(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=3;d=100;m=exp;b=3;sa=50;j=0', $this->failing(['*' => new HttpFailure(503)]));

        self::assertSame([50, 100, 300], $sleeps);
    }

    /** A spec with no d= keeps 1.0.x behaviour: sa= is the exponential base. */
    public function testExponentialWithoutDStillUsesStartAfterAsTheBase(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=4;m=exp;b=2;sa=100;j=0', $this->failing(['*' => new HttpFailure(503)]));

        self::assertSame([100, 100, 200, 400], $sleeps);
    }

    // ----- Bug 2: linear mode steps by d= -----

    public function testLinearStepsByD(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=4;d=100;m=lin;j=0', $this->failing(['*' => new HttpFailure(503)]));

        self::assertSame([100, 200, 300], $sleeps);
    }

    // ----- Bug 3: no cap= means uncapped; cap= caps the delay, jittered below it -----

    public function testNoCapMeansUncapped(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=6;d=1s;m=exp;b=4;j=0', $this->failing(['*' => new HttpFailure(503)]));

        self::assertSame([1000, 4000, 16000, 64000, 256000], $sleeps);
    }

    public function testCapClampsTheNominalDelay(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=6;d=1s;m=exp;b=2;cap=3s;j=0', $this->failing(['*' => new HttpFailure(503)]));

        self::assertSame([1000, 2000, 3000, 3000, 3000], $sleeps);
    }

    /** Full jitter applies to the capped delay, so capped sleeps spread over [0, cap] instead of piling up at cap. */
    public function testCappedDelaysAreStillJittered(): void
    {
        $spec = 'rtry:a=5;d=1s;m=exp;b=2;cap=3s;j=100%@full';
        $atCap = 0;
        for ($run = 0; $run < self::RUNS; $run++) {
            [, $sleeps] = $this->runSpec($spec, $this->failing(['*' => new HttpFailure(503)]));
            self::assertWithin([[0, 1000], [0, 2000], [0, 3000], [0, 3000]], $sleeps, $spec);
            $atCap += $sleeps[3] === 3000 ? 1 : 0;
        }
        self::assertLessThan(5, $atCap, 'a capped delay should land on exactly cap about 1 time in 3001');
    }

    public function testPlusMinusJitterNeverExceedsCap(): void
    {
        $spec = 'rtry:a=4;d=1s;m=exp;b=2;cap=2s;j=20%@pm';
        for ($run = 0; $run < self::RUNS; $run++) {
            [, $sleeps] = $this->runSpec($spec, $this->failing(['*' => new HttpFailure(503)]));
            self::assertWithin([[800, 1200], [1600, 2000], [1600, 2000]], $sleeps, $spec);
        }
    }

    // ----- Bug 5: full jitter draws from [delay - window, delay] -----

    public function testFullJitterWindowIsBelowTheDelay(): void
    {
        $spec = 'rtry:a=3;d=8s;m=exp;b=2;j=100@full';
        for ($run = 0; $run < self::RUNS; $run++) {
            [, $sleeps] = $this->runSpec($spec, $this->failing(['*' => new HttpFailure(503)]));
            self::assertWithin([[7900, 8000], [15900, 16000]], $sleeps, $spec);
        }
    }

    public function testFullJitterPercentIsBelowTheDelay(): void
    {
        $spec = 'rtry:a=3;d=1s;m=lin;j=50%@full';
        for ($run = 0; $run < self::RUNS; $run++) {
            [, $sleeps] = $this->runSpec($spec, $this->failing(['*' => new HttpFailure(503)]));
            self::assertWithin([[500, 1000], [1000, 2000]], $sleeps, $spec);
        }
    }

    public function testZeroJitterIsNoJitterNotZeroDelay(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=3;d=250;m=lin;j=0', $this->failing(['*' => new HttpFailure(503)]));

        self::assertSame([250, 500], $sleeps);
    }

    /** The README quick-start spec (with m=, the key 1.x parses), through four 503s. */
    public function testReadmeSpecSleepsAJitteredExponentialSchedule(): void
    {
        $spec = 'rtry:a=5;d=200;m=exp;b=2;cap=8s;j=100@full;on=5xx,ETIMEDOUT';
        for ($run = 0; $run < self::RUNS; $run++) {
            [$value, $sleeps] = $this->runSpec($spec, $this->failing([1 => new HttpFailure(503), 2 => new HttpFailure(503), 3 => new HttpFailure(503), 4 => new HttpFailure(503)]));
            self::assertSame('ok', $value);
            self::assertWithin([[100, 200], [300, 400], [700, 800], [1500, 1600]], $sleeps, $spec);
        }
    }

    // ----- Bug 6: on= decides what retries -----

    public function testOnStopsANonRetryableStatusAfterOneAttempt(): void
    {
        $error = new HttpFailure(422);
        [$value, $sleeps] = $this->runSpec('rtry:a=5;d=10;m=lin;j=0;on=5xx,ETIMEDOUT', $this->failing(['*' => $error]));

        self::assertSame($error, $value, 'the 422 is rethrown');
        self::assertSame([1], $this->seen);
        self::assertSame([], $sleeps);
    }

    public function testOnRetriesTheStatusesAndTagsItNames(): void
    {
        [$value, $sleeps] = $this->runSpec(
            'rtry:a=4;d=10;m=lin;j=0;on=5xx,ETIMEDOUT,429',
            $this->failing([1 => new HttpFailure(503), 2 => new \RuntimeException('Connection timed out'), 3 => new HttpFailure(429)])
        );

        self::assertSame('ok', $value);
        self::assertSame([1, 2, 3, 4], $this->seen);
        self::assertSame([10, 20, 30], $sleeps);
    }

    public function testOnDefaultExpandsToTheDefaultTokens(): void
    {
        [$value] = $this->runSpec('rtry:a=3;d=10;m=lin;j=0;on=default', $this->failing([1 => new HttpFailure(500), 2 => new \RuntimeException('connection refused')]));
        self::assertSame('ok', $value);
        self::assertSame([1, 2, 3], $this->seen);

        [$value] = $this->runSpec('rtry:a=3;d=10;m=lin;j=0;on=default', $this->failing(['*' => new HttpFailure(404)]));
        self::assertInstanceOf(HttpFailure::class, $value);
        self::assertSame([1], $this->seen);
    }

    public function testOnTokensReachACallerSuppliedTokenDecider(): void
    {
        $decider = new OnTokensDecider([]);
        (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10;on=5xx,429,standard', $decider);

        self::assertSame(array_merge(['5XX', 429], FailureToken::defaults()), $decider->getTokens());
    }

    public function testACallerSuppliedDeciderStillWinsOverOn(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=3;d=10;m=lin;j=0;on=5xx', $this->failing(['*' => new HttpFailure(422)]), new AlwaysRetryDecider());

        self::assertSame([1, 2, 3], $this->seen);
        self::assertSame([10, 20], $sleeps);
    }

    public function testWithoutOnEverythingStillRetriesAsIn1_0(): void
    {
        [, $sleeps] = $this->runSpec('rtry:a=3;d=10;m=lin;j=0', $this->failing(['*' => new HttpFailure(422)]));

        self::assertSame([1, 2, 3], $this->seen);
        self::assertSame([10, 20], $sleeps);
    }

    /** on=429 adds the rate-limit rule it always generated; it now reaches the policy, so Retry-After is waited out. */
    public function testOn429WaitsOutRetryAfter(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(429);
        $response->method('getHeaders')->willReturn(['Retry-After' => ['2']]);

        [$value, $sleeps] = $this->runSpec('rtry:a=2;d=100;m=lin;j=0;on=429', $this->failing([1 => new HttpFailure(429, $response)]));

        self::assertSame('ok', $value);
        self::assertSame([2000], $sleeps);
    }
}
