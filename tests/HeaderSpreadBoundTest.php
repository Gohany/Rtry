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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The spread above a server's Retry-After is the policy's jitter for the next attempt, bounded
 * by the larger of 1 s and 10 % of the server's wait (2.5.0). Before, it was the whole jitter
 * window: after attempt 2 of `d=10s` that is up to 20 s on top of the server's wait, which
 * pushes the third attempt toward `dl` (Foundation's llm.mapper, under dl=30s, lost about 30 %
 * of its third attempts to it).
 *
 * The bound scales the draw rather than clipping it, so the spread stays uniform over
 * [0, bound] and callers told the same time do not all come back at its edge. The room left
 * before `dl` (and before the scheduler's ceiling) is part of the bound, so a server time
 * close to `dl` scales the draw into what is left rather than piling the herd on `dl` − 1 ms.
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
                // 10 % of 20 s. Before 2.5.0: up to 10 s above it after attempt 1, 20 s after attempt 2.
                self::assertLessThanOrEqual(22_000, $sleep, "seed $seed: at most 2 s above it");
            }
            // 4 + 22 + 4 + 22: at least 8 s of dl left for it. Before 2.5.0 it could start at 59.999 s.
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
        for ($seed = 1; $seed <= 200; $seed++) {
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
        // Scaled into the 999 ms left, not clipped to its last millisecond (2.4.1 and the first
        // cut of 2.5.0 put two thirds of these on 29,999 ms).
        self::assertLessThanOrEqual(3, count(array_keys($sleeps, 29_999, true)), 'no pile-up on dl - 1 ms');
        self::assertGreaterThan(160, count(array_unique($sleeps)), 'spread over the room left');
        self::assertGreaterThan(29_900, max($sleeps));
        self::assertLessThan(29_100, min($sleeps));
    }

    // ---- Herds: clients that started together and were told the same Retry-After --------------

    /**
     * $clients clients run $spec through Retry, each with its own seed, against a server that
     * answers every attempt but the last with 429 and Retry-After: $retryAfter; each attempt
     * takes $attemptMs. Returns, for each attempt number, the millisecond (from the start) each
     * client began it.
     *
     * @return array<int, list<int>>
     */
    private static function herd(string $spec, int $attemptMs, string $retryAfter, int $clients): array
    {
        $policy = (new RtryPolicyFactory())->fromSpec($spec);
        $starts = [];
        for ($seed = 1; $seed <= $clients; $seed++) {
            $clock = new FrozenClock(self::T0);
            $retry = new Retry($clock, new RecordingSleeper($clock), null, SeededRandom::randomizer($seed));
            $last = $policy->attempts();
            $retry->tryOutcome(static function (AttemptContextInterface $a) use (&$starts, $clock, $attemptMs, $retryAfter, $last): string {
                $starts[$a->attemptNumber()][] = $clock->nowUnixMs() - self::T0;
                $clock->advanceMs($attemptMs);
                if ($a->attemptNumber() < $last) {
                    throw HttpFailure::status(429, ['Retry-After' => $retryAfter]);
                }
                return 'ok';
            }, $policy);
        }
        return $starts;
    }

    /** @param list<int> $starts */
    private static function busiestMillisecond(array $starts): int
    {
        return $starts === [] ? 0 : max(array_count_values($starts));
    }

    /**
     * The herd table of the hostile review of PR #10 (Gohany/Rtry#10, should-fix 1): 10,000
     * clients each, the busiest millisecond of the attempt the server's time pushes toward
     * `dl`. 2.4.1 and the first cut of 2.5.0 (0108c1d) clipped the spread at `dl` - 1 ms; the
     * spread scaled into the room left gives the last column:
     *
     *                                                         2.4.1           0108c1d         scaled
     *   rtry:a=2;d=10s;dl=30s, Retry-After: 29, attempt 2      9,018           6,610           22
     *   the same, Retry-After: 27                              7,025           13              13
     *   seq=5s,20s, 3 s attempts, Retry-After: 10, attempt 3   4,346 of 4,841  13 of 6,797     13 of 6,797
     *   seq=5s,20s, 2.5 s attempts, Retry-After: 12, attempt 3 1,194 of 1,234  3,095 of 5,253  38 of 5,253
     *
     * The 38 are clients whose own server time is a few milliseconds before `dl`: their room is
     * that small, so they land just below it whatever the draw.
     *
     * @return iterable<string, array{string, int, string, int, int}>
     */
    public static function herds(): iterable
    {
        $mapper = 'rtry:a=3;m=seq;seq=5s,20s;j=100%@full;dl=30s';
        yield 'Retry-After: 29 under dl=30s' => ['rtry:a=2;d=10s;dl=30s', 0, '29', 2, 9_000];
        yield 'Retry-After: 27 under dl=30s' => ['rtry:a=2;d=10s;dl=30s', 0, '27', 2, 9_000];
        yield 'seq=5s,20s under dl=30s, 3 s attempts, Retry-After: 10' => [$mapper, 3_000, '10', 3, 4_000];
        yield 'seq=5s,20s under dl=30s, 2.5 s attempts, Retry-After: 12' => [$mapper, 2_500, '12', 3, 4_000];
    }

    #[DataProvider('herds')]
    public function testAHerdToldATimeNearDlIsSpreadOverTheRoomLeftNotPiledOnItsLastMillisecond(string $spec, int $attemptMs, string $retryAfter, int $attempt, int $atLeast): void
    {
        $starts = self::herd($spec, $attemptMs, $retryAfter, 10_000)[$attempt] ?? [];
        self::assertGreaterThanOrEqual($atLeast, count($starts), 'the clients still reach the attempt');
        self::assertLessThan(30_000, max($starts), 'never at or past dl');
        self::assertLessThanOrEqual(50, self::busiestMillisecond($starts), 'no millisecond holds a pile-up');
        self::assertLessThanOrEqual(10, count(array_keys($starts, 29_999, true)), 'dl - 1 ms is not where the cut lands');
    }

    public function testAHerdFarFromDlIsUnchangedByTheRoom(): void
    {
        // Retry-After: 4 after a 1 s first attempt leaves 25 s of dl: the room is wider than the
        // bound, so the draws are the bound's alone (every millisecond from 5000 to 6000 used).
        $starts = self::herd('rtry:a=3;m=seq;seq=5s,20s;j=100%@full;dl=30s', 1_000, '4', 2_000)[2];
        self::assertGreaterThanOrEqual(5_000, min($starts));
        self::assertLessThanOrEqual(6_000, max($starts));
        self::assertGreaterThan(800, count(array_unique($starts)));
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

    public function testTheDurablePathScalesIntoTheRoomLeftBeforeDlToo(): void
    {
        // A durable retry whose attempt context has 30 s of dl left, told Retry-After: 29.5.
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10s');
        $context = new RtryAttemptContext(2, 3, 10_000, 0, 30_000, []);
        $waits = [];
        $fromError = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $clock = new FrozenClock(self::T0);
            $scheduler = new RtryScheduler($clock, SeededRandom::randomizer($seed));
            $outcome = $scheduler->outcome(HttpFailure::status(503, ['Retry-After' => '29']), $policy, $context);
            $decision = $scheduler->next($policy, $outcome, $context);
            self::assertTrue($decision->retry(), "seed $seed");
            $waits[] = $decision->delayMs();
            $fromError[] = (int) RtryAttemptOutcome::fromError(HttpFailure::status(503, ['Retry-After' => '29']), null, $clock, $policy, 2, SeededRandom::randomizer($seed), $context)->notBeforeUnixMs() - self::T0;
        }
        foreach ([$waits, $fromError] as $spread) {
            self::assertGreaterThanOrEqual(29_000, min($spread));
            self::assertLessThan(30_000, max($spread));
            self::assertLessThanOrEqual(3, count(array_keys($spread, 29_999, true)), 'no pile-up on dl - 1 ms');
            self::assertGreaterThan(300, count(array_unique($spread)));
        }
        self::assertSame($waits, $fromError, 'outcome() and fromError() with the same context draw the same');
    }

    public function testTheRoomBelowTheSchedulersCeilingScalesTheDrawToo(): void
    {
        // A ceiling of 9.5 s and a server asking for 9 s: 500 ms left, scaled into, not piled on 9.5 s.
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10s');
        $context = new RtryAttemptContext(1, 3, 10_000, 0, null, []);
        $waits = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $clock = new FrozenClock(self::T0);
            $scheduler = new RtryScheduler($clock, SeededRandom::randomizer($seed), 9_500);
            $outcome = $scheduler->outcome(HttpFailure::status(503, ['Retry-After' => '9']), $policy, $context);
            $notBefore = $outcome->notBeforeUnixMs();
            self::assertNotNull($notBefore);
            $waits[] = $notBefore - self::T0;
        }
        self::assertGreaterThanOrEqual(9_000, min($waits));
        self::assertLessThanOrEqual(9_500, max($waits));
        self::assertGreaterThan(9_450, max($waits));
        self::assertLessThanOrEqual(5, count(array_keys($waits, 9_500, true)), 'no pile-up on the ceiling');
        self::assertGreaterThan(250, count(array_unique($waits)));
    }

    public function testTheRoomArgumentScalesTheDrawIntoWhatIsLeft(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10s');
        $wide = $narrow = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $wide[] = RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer($seed), 20_000);
            $narrow[] = RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer($seed), 20_000, 500);
        }
        // Room wider than the bound: the bound's draws, unchanged.
        $roomy = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $roomy[] = RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer($seed), 20_000, 5_000);
        }
        self::assertSame($wide, $roomy);
        self::assertLessThanOrEqual(500, max($narrow));
        self::assertGreaterThan(450, max($narrow));
        self::assertLessThanOrEqual(3, count(array_keys($narrow, 500, true)));
        // Seed for seed, the narrow draw is the wide one scaled: same order, never larger.
        foreach ($wide as $i => $w) {
            self::assertLessThanOrEqual($w, $narrow[$i]);
        }
        // No room: no spread, and a window already inside the room is unchanged.
        self::assertSame(0, RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer(1), 20_000, 0));
        self::assertSame(0, RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer(1), 20_000, -5));
        $small = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100');
        for ($seed = 1; $seed <= 50; $seed++) {
            self::assertSame(
                RtryScheduler::headerSpreadMs($small, 1, SeededRandom::randomizer($seed), 2_000),
                RtryScheduler::headerSpreadMs($small, 1, SeededRandom::randomizer($seed), 2_000, 150),
            );
        }
    }

    // ---- Mutation pins (review of PR #10, nit 5): jitterWindowMs()'s full/pm branch ------------

    public function testAFullJittersFixedWindowWiderThanItsBackoffIsTheBackoff(): void
    {
        // d=100;j=8s draws from [0, 100]: inside the bound, so draw for draw as without the wait.
        self::assertSame(self::spreads('rtry:a=3;d=100;j=8s', 1, null, 100), self::spreads('rtry:a=3;d=100;j=8s', 1, 5_000, 100));
    }

    public function testAPlusMinusFixedWindowWiderThanItsBackoffIsScaledNotClipped(): void
    {
        // d=100;j=8s@pm draws up to 8 s above the backoff: scaled into 1 s, not piled on it.
        $spreads = self::spreads('rtry:a=3;d=100;j=8s@pm', 1, 5_000);
        self::assertLessThanOrEqual(1_000, max($spreads));
        self::assertLessThan(5, count(array_keys($spreads, 1_000, true)), 'not clipped to the bound');
        self::assertGreaterThan(120, count(array_unique($spreads)));
    }
}
