<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptContext;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;
use Gohany\Rtry\Tests\Support\ForeignPolicy;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\TestCase;

/**
 * The spread above a server's Retry-After is the policy's jitter for the next attempt, bounded
 * by the larger of 1 s and 10 % of the server's wait (2.4.2). Before, it was the whole jitter
 * window: after attempt 2 of `d=10s` that is up to 20 s on top of the server's wait, which
 * pushes the third attempt toward `dl` (Foundation's llm.mapper, under dl=30s, lost about 30 %
 * of its third attempts to it).
 *
 * The bound scales the draw rather than clipping it, so the spread stays uniform over
 * [0, bound] and callers told the same time do not all come back at its edge.
 */
final class HeaderSpreadBoundTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    /** @return list<int> the spread over $seeds seeds */
    private static function spreads(string $spec, int $failedAttempt, ?int $serverWaitMs, int $seeds = 400): array
    {
        $policy = (new RtryPolicyFactory())->fromSpec($spec);
        $out = [];
        for ($seed = 1; $seed <= $seeds; $seed++) {
            $out[] = RtryScheduler::headerSpreadMs($policy, $failedAttempt, SeededRandom::randomizer($seed), $serverWaitMs);
        }
        return $out;
    }

    public function testTheSpreadIsAtMostOneSecondAboveAShortServerWait(): void
    {
        // The policy's window after attempt 2 is 20 s; the server asked for 5 s.
        $spreads = self::spreads('rtry:a=3;d=10s', 2, 5_000);
        self::assertLessThanOrEqual(1_000, max($spreads));
        self::assertGreaterThanOrEqual(0, min($spreads));
        // Scaled, not clipped: spread over the whole range, and no pile-up at the bound.
        self::assertGreaterThan(900, max($spreads));
        self::assertLessThan(100, min($spreads));
        self::assertLessThan(5, count(array_keys($spreads, 1_000, true)), 'not clipped to the bound');
        self::assertGreaterThan(300, count(array_unique($spreads)));
    }

    public function testTheSpreadIsAtMostTenPercentAboveALongServerWait(): void
    {
        $spreads = self::spreads('rtry:a=3;d=100s', 2, 60_000);
        self::assertLessThanOrEqual(6_000, max($spreads));
        self::assertGreaterThan(5_000, max($spreads));
    }

    public function testASpreadAlreadyInsideTheBoundIsUnchanged(): void
    {
        // A 100 ms window: the same draw with or without the server's wait.
        self::assertSame(self::spreads('rtry:a=3;d=100', 1, null, 50), self::spreads('rtry:a=3;d=100', 1, 2_000, 50));
        // And with a window exactly at the bound.
        self::assertSame(self::spreads('rtry:a=3;d=1s', 1, null, 50), self::spreads('rtry:a=3;d=1s', 1, 5_000, 50));
    }

    public function testWithoutTheServersWaitTheSpreadIsAsIn24(): void
    {
        $spreads = self::spreads('rtry:a=3;d=10s', 2, null);
        self::assertGreaterThan(15_000, max($spreads), 'the whole window, for callers that do not pass the wait');
    }

    public function testNoJitterMeansNoSpread(): void
    {
        self::assertSame([0], array_values(array_unique(self::spreads('rtry:a=3;d=10s;j=0', 2, 5_000, 20))));
    }

    public function testPlusMinusJitterIsBoundedToo(): void
    {
        $spreads = self::spreads('rtry:a=3;d=10s;j=50%@pm', 2, 5_000);
        self::assertLessThanOrEqual(1_000, max($spreads));
        self::assertGreaterThan(900, max($spreads));
    }

    public function testAFixedWindowIsBoundedToo(): void
    {
        $spreads = self::spreads('rtry:a=3;d=10s;j=8s', 2, 5_000);
        self::assertLessThanOrEqual(1_000, max($spreads));
        self::assertGreaterThan(900, max($spreads));
    }

    public function testAPolicyOtherThanRtrysIsBoundedThroughItsJittersWindow(): void
    {
        $policy = new ForeignPolicy((new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10s'));
        $spreads = [];
        for ($seed = 1; $seed <= 200; $seed++) {
            $spreads[] = RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer($seed), 5_000);
        }
        self::assertLessThanOrEqual(1_000, max($spreads));
        self::assertGreaterThan(900, max($spreads));
    }

    // ---- End to end: a spec, a server that says Retry-After: 5, the sleeps Retry takes -------

    /**
     * Three attempts, 10 s steps, a 60 s deadline, a provider that answers 429 Retry-After: 20
     * twice, and attempts that take 4 s each. The server's wait is longer than the policy's
     * own jittered delay (at most 10 s, then 20 s), so the sleep is the server's wait plus the
     * spread (SPEC 5.11: the larger of the two).
     *
     * @return array{list<int>, int, ?GiveUpReason, int}
     */
    private static function rateLimitedRun(int $seed): array
    {
        $clock = new FrozenClock(self::T0);
        $sleeper = new RecordingSleeper($clock);
        $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer($seed));
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10s;dl=60s');
        $ran = 0;
        $thirdStartedAt = 0;
        $result = $retry->tryOutcome(static function (AttemptContextInterface $a) use (&$ran, &$thirdStartedAt, $clock): string {
            $ran++;
            if ($a->attemptNumber() === 3) {
                $thirdStartedAt = $clock->nowUnixMs() - self::T0;
            }
            $clock->advanceMs(4_000);
            if ($a->attemptNumber() < 3) {
                throw HttpFailure::status(429, ['Retry-After' => '20']);
            }
            return 'mapped';
        }, $policy);
        return [$sleeper->sleeps(), $ran, $result->giveUpReason(), $thirdStartedAt];
    }

    public function testASpecFollowingRetryAfterSleepsJustAboveTheServersWaitAndLeavesTheThirdAttemptItsTime(): void
    {
        $first = [];
        for ($seed = 1; $seed <= 200; $seed++) {
            [$sleeps, $ran, $reason, $thirdStartedAt] = self::rateLimitedRun($seed);
            self::assertSame(3, $ran, "seed $seed: the third attempt ran");
            self::assertNull($reason, "seed $seed");
            self::assertCount(2, $sleeps);
            foreach ($sleeps as $sleep) {
                self::assertGreaterThanOrEqual(20_000, $sleep, "seed $seed: never below the server's wait");
                // 10 % of 20 s. Before 2.4.2: up to 10 s above it after attempt 1, 20 s after attempt 2.
                self::assertLessThanOrEqual(22_000, $sleep, "seed $seed: at most 2 s above it");
            }
            // 4 + 22 + 4 + 22: at least 8 s of dl left for it. Before 2.4.2 it could start at 59.999 s.
            self::assertLessThanOrEqual(52_000, $thirdStartedAt, "seed $seed");
            $first[] = $sleeps[0];
        }
        self::assertGreaterThan(150, count(array_unique($first)), 'callers told the same time are spread');
        self::assertGreaterThan(21_800, max($first), 'over the whole bound');
        self::assertLessThan(20_200, min($first));
    }

    public function testTheBoundStillNeverPushesAWaitThatFitsPastDl(): void
    {
        // Retry-After: 29 inside dl=30s after a 0 ms attempt: 10 % is 2.9 s, but dl leaves 1 s.
        // The policy's own delay before attempt 2 is at most 10 s.
        $sleeps = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $clock = new FrozenClock(self::T0);
            $sleeper = new RecordingSleeper($clock);
            $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer($seed));
            $retry->tryOutcome(static function (AttemptContextInterface $a): string {
                if ($a->attemptNumber() === 1) {
                    throw HttpFailure::status(503, ['Retry-After' => '29']);
                }
                return 'ok';
            }, (new RtryPolicyFactory())->fromSpec('rtry:a=2;d=10s;dl=30s'));
            self::assertCount(1, $sleeper->sleeps(), "seed $seed");
            self::assertGreaterThanOrEqual(29_000, $sleeper->sleeps()[0]);
            self::assertLessThan(30_000, $sleeper->sleeps()[0]);
            $sleeps[] = $sleeper->sleeps()[0];
        }
        self::assertGreaterThan(10, count(array_unique($sleeps)), 'still spread below dl');
    }

    public function testTheDurablePathIsBoundedTheSameWay(): void
    {
        // Before attempt 3 of d=10s the policy's own delay is at most 20 s; the server says 30 s.
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10s');
        $waits = [];
        for ($seed = 1; $seed <= 200; $seed++) {
            $clock = new FrozenClock(self::T0);
            $scheduler = new RtryScheduler($clock, SeededRandom::randomizer($seed));
            $context = new RtryAttemptContext(2, 3, 10_000, 0, null, []);
            $outcome = $scheduler->outcome(HttpFailure::status(429, ['Retry-After' => '30']), $policy, $context);
            $decision = $scheduler->next($policy, $outcome, $context);
            self::assertTrue($decision->retry(), "seed $seed");
            $waits[] = $decision->delayMs();
        }
        self::assertGreaterThanOrEqual(30_000, min($waits));
        self::assertLessThanOrEqual(33_000, max($waits));
        self::assertGreaterThan(150, count(array_unique($waits)));

        // fromError() with the policy: the server's time moves by at most max(1 s, 10 %).
        $waits = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $outcome = RtryAttemptOutcome::fromError(HttpFailure::status(429, ['Retry-After' => '5']), null, new FrozenClock(self::T0), $policy, 2, SeededRandom::randomizer($seed));
            $waits[] = (int) $outcome->notBeforeUnixMs() - self::T0;
        }
        self::assertGreaterThanOrEqual(5_000, min($waits));
        self::assertLessThanOrEqual(6_000, max($waits));
        self::assertGreaterThan(5_800, max($waits));
    }
}
