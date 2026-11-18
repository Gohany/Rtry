<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptContext;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PR #6 hostile review, blocker 3 and should-fixes 1 and 2: a server's wait is parsed without
 * a TypeError or a PHP warning, whatever the header says; a wait longer than the scheduler's
 * ceiling (a day by default) or the deadline gives up instead of sleeping; workers told the
 * same Retry-After spread out above it; and RateLimit-Reset headers set a wait only on a 429
 * or a 503, where they mean "rate limited".
 *
 * End to end: parse a real rtry: string, run Retry, record the sleeps.
 */
final class HeaderWaitsTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    /** @return array{int, ?GiveUpReason} */
    private function attempt(string $spec, \Throwable $error, int $seed = 1118, ?RuleBasedFailureClassifier $classifier = null): array
    {
        $clock = new FrozenClock();
        $sleeper = new RecordingSleeper($clock);
        $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer($seed));
        $policy = (new RtryPolicyFactory(null, $classifier))->fromSpec($spec);

        // A framework that turns every PHP warning into an exception (Laravel, Symfony).
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $result = $retry->tryOutcome(static fn () => throw $error, $policy);
        } finally {
            restore_error_handler();
        }

        $this->sleeps = $sleeper->sleeps();
        self::assertSame($error, $result->lastError(), 'the caller always gets its own error back');
        return [$result->attempts(), $result->giveUpReason()];
    }

    // ---- blocker 3: hostile header values ---------------------------------------------------

    /** @return iterable<string, array{int, array<string,mixed>, string}> status, headers, outcome */
    public static function hostileHeaders(): iterable
    {
        yield 'Retry-After @epoch overflow' => [503, ['Retry-After' => '@9223372036854776'], 'ignored'];
        yield 'Retry-After 9223372036854770' => [503, ['Retry-After' => '9223372036854770'], 'gives_up'];
        yield 'Retry-After 9223372036854775 (wraps negative)' => [503, ['Retry-After' => '9223372036854775'], 'gives_up'];
        yield 'Retry-After 99999999999999999999' => [503, ['Retry-After' => '99999999999999999999'], 'gives_up'];
        yield 'Retry-After 1e400' => [503, ['Retry-After' => '1e400'], 'gives_up'];
        yield 'Retry-After a year' => [503, ['Retry-After' => '31536000'], 'gives_up'];
        yield 'Retry-After next year' => [503, ['Retry-After' => 'next year'], 'ignored'];
        yield 'Retry-After tomorrow' => [503, ['Retry-After' => 'tomorrow'], 'ignored'];
        yield 'Retry-After negative' => [503, ['Retry-After' => '-5'], 'ignored'];
        yield 'Retry-After NaN' => [503, ['Retry-After' => 'NaN'], 'ignored'];
        yield 'Retry-After nested list' => [503, ['Retry-After' => [['2']]], 'ignored'];
        yield 'Retry-After a date in year 9999' => [503, ['Retry-After' => 'Fri, 31 Dec 9999 23:59:59 GMT'], 'gives_up'];
        yield 'RateLimit-Reset-After huge' => [429, ['RateLimit-Reset-After' => '9223372036854770'], 'gives_up'];
        yield 'X-RateLimit-Reset 1e400' => [429, ['X-RateLimit-Reset' => '1e400'], 'gives_up'];
        yield 'X-RateLimit-Reset-Ms huge' => [429, ['X-RateLimit-Reset-Ms' => '99999999999999999999'], 'gives_up'];
        yield 'RateLimit-Reset 1e308' => [503, ['RateLimit-Reset' => '1e308'], 'gives_up'];
    }

    /** @param array<string,mixed> $headers */
    #[DataProvider('hostileHeaders')]
    public function testAHostileHeaderNeverReplacesTheCallersError(int $status, array $headers, string $outcome): void
    {
        $error = HttpFailure::status($status, $headers);

        $result = $this->attempt('rtry:a=2;d=100;j=0;cap=1s', $error);

        if ($outcome === 'gives_up') {
            self::assertSame([1, GiveUpReason::DeadlineExceeded], $result);
            self::assertSame([], $this->sleeps, 'never sleeps for an absurd wait');
        } else {
            self::assertSame([2, GiveUpReason::AttemptsExhausted], $result);
            self::assertSame([100], $this->sleeps, 'an unreadable header is ignored');
        }
    }

    public function testTheDurablePathParsesHostileHeadersToo(): void
    {
        $clock = new FrozenClock();
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $outcome = RtryAttemptOutcome::fromError(HttpFailure::status(503, ['Retry-After' => '99999999999999999999']), null, $clock);
        } finally {
            restore_error_handler();
        }

        self::assertIsInt($outcome->notBeforeUnixMs());
        self::assertGreaterThan($clock->nowUnixMs() + 86_400_000, $outcome->notBeforeUnixMs());

        $decision = (new RtryScheduler($clock))->next(
            (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100'),
            $outcome,
            new RtryAttemptContext(1, 3, 0, 0, null),
        );
        self::assertSame(GiveUpReason::DeadlineExceeded, $decision->giveUpReason());
    }

    public function testACustomRuleWithAnAbsurdWaitGivesUpInsteadOfThrowing(): void
    {
        $rule = new class implements RuleInterface {
            public function apply(\Throwable $e): FailureMetadata
            {
                return new FailureMetadata(503, [], [], PHP_INT_MAX, PHP_INT_MAX);
            }
        };

        $result = $this->attempt('rtry:a=3;d=100;j=0', new \RuntimeException('boom'), 1118, new RuleBasedFailureClassifier([$rule]));

        self::assertSame([1, GiveUpReason::DeadlineExceeded], $result);
    }

    public function testANotBeforeFarInThePastIsIgnored(): void
    {
        $rule = new class implements RuleInterface {
            public function apply(\Throwable $e): FailureMetadata
            {
                return new FailureMetadata(503, [], [], null, PHP_INT_MIN);
            }
        };

        $result = $this->attempt('rtry:a=2;d=100;j=0', new \RuntimeException('boom'), 1118, new RuleBasedFailureClassifier([$rule]));

        self::assertSame([2, GiveUpReason::AttemptsExhausted], $result);
        self::assertSame([100], $this->sleeps);
    }

    // ---- what bounds a server's wait ---------------------------------------------------------

    public function testAWaitUnderTheCeilingIsHonouredEvenAboveTheCap(): void
    {
        // gohany/retry SPEC 5.11 step 5: a Retry-After above cap is honoured.
        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0;cap=1s', HttpFailure::status(503, ['Retry-After' => '3600'])));
        self::assertSame([3_600_000], $this->sleeps);
    }

    public function testAWaitPastTheDeadlineGivesUpWithoutSleeping(): void
    {
        self::assertSame([1, GiveUpReason::DeadlineExceeded], $this->attempt('rtry:a=3;d=100;j=0;dl=5s', HttpFailure::status(429, ['Retry-After' => '10'])));
        self::assertSame([], $this->sleeps);
    }

    public function testTheCeilingIsADayByDefaultAndConfigurable(): void
    {
        $clock = new FrozenClock();
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');
        $context = new RtryAttemptContext(1, 3, 0, 0, null);
        $at = static fn (int $ms) => RtryAttemptOutcome::failure(new \RuntimeException('x'), 503, [], $clock->nowUnixMs() + $ms);

        self::assertSame(86_400_000, RtryScheduler::MAX_HEADER_WAIT_MS);
        self::assertSame(86_400_000, (new RtryScheduler($clock))->next($policy, $at(86_400_000), $context)->delayMs());
        self::assertSame(GiveUpReason::DeadlineExceeded, (new RtryScheduler($clock))->next($policy, $at(86_400_001), $context)->giveUpReason());

        $short = new RtryScheduler($clock, null, 5_000);
        self::assertSame(5_000, $short->next($policy, $at(5_000), $context)->delayMs());
        self::assertSame(GiveUpReason::DeadlineExceeded, $short->next($policy, $at(5_001), $context)->giveUpReason());
    }

    // ---- should-fix 1: jitter above the server's floor ---------------------------------------

    public function testWorkersToldTheSameRetryAfterSpreadOutAboveIt(): void
    {
        foreach (['503 Retry-After 2' => [503, '2', 2000], '429 Retry-After 30' => [429, '30', 30000]] as $label => [$status, $header, $floor]) {
            $sleeps = [];
            for ($seed = 1; $seed <= 50; $seed++) {
                $this->attempt('rtry:a=2;d=100', HttpFailure::status($status, ['Retry-After' => $header]), $seed);
                $sleeps[] = $this->sleeps[0];
            }
            self::assertGreaterThanOrEqual($floor, min($sleeps), "$label: never before the server's time");
            self::assertLessThanOrEqual($floor + 100, max($sleeps), "$label: at most the jitter window above it");
            self::assertGreaterThan(25, count(array_unique($sleeps)), "$label: 50 workers must not wake together");
        }
    }

    public function testPlusMinusJitterAlsoOnlyAddsAboveTheFloor(): void
    {
        $sleeps = [];
        for ($seed = 1; $seed <= 30; $seed++) {
            $this->attempt('rtry:a=2;d=100;j=50%@pm', HttpFailure::status(503, ['Retry-After' => '2']), $seed);
            $sleeps[] = $this->sleeps[0];
        }
        self::assertGreaterThanOrEqual(2000, min($sleeps));
        self::assertLessThanOrEqual(2050, max($sleeps));
        self::assertGreaterThan(5, count(array_unique($sleeps)));
    }

    public function testNoJitterMeansExactlyTheServersTime(): void
    {
        $this->attempt('rtry:a=2;d=100;j=0', HttpFailure::status(503, ['Retry-After' => '2']));
        self::assertSame([2000], $this->sleeps);
    }

    public function testTheSpreadNeverPushesAWaitPastTheDeadline(): void
    {
        for ($seed = 1; $seed <= 30; $seed++) {
            $result = $this->attempt('rtry:a=2;d=100;dl=2050', HttpFailure::status(503, ['Retry-After' => '2']), $seed);
            self::assertSame([2, GiveUpReason::AttemptsExhausted], $result, "seed $seed");
            self::assertGreaterThanOrEqual(2000, $this->sleeps[0]);
            self::assertLessThan(2050, $this->sleeps[0]);
        }
    }

    public function testTheDurablePathSpreadsAboveTheServersTimeWhenGivenThePolicy(): void
    {
        $clock = new FrozenClock();
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100');
        $times = [];
        for ($seed = 1; $seed <= 30; $seed++) {
            $outcome = RtryAttemptOutcome::fromError(
                HttpFailure::status(429, ['Retry-After' => '2']),
                null,
                $clock,
                $policy,
                1,
                SeededRandom::randomizer($seed),
            );
            $times[] = $outcome->notBeforeUnixMs() - $clock->nowUnixMs();
        }
        self::assertGreaterThanOrEqual(2000, min($times));
        self::assertLessThanOrEqual(2100, max($times));
        self::assertGreaterThan(10, count(array_unique($times)));

        // Without a policy the server's time stands as it was.
        self::assertSame($clock->nowUnixMs() + 2000, RtryAttemptOutcome::fromError(HttpFailure::status(429, ['Retry-After' => '2']), null, $clock)->notBeforeUnixMs());
    }

    // ---- should-fix 2: rate-limit reset headers only where they mean rate limited -------------

    public function testAResetHeaderOnA5xxThatIsNotA503SetsNoWait(): void
    {
        $topOfTheHour = (string) (intdiv((new FrozenClock())->nowUnixMs(), 1000) + 3500);

        $this->attempt('rtry:a=4;d=100;j=0;cap=1s', HttpFailure::status(502, ['X-RateLimit-Reset' => $topOfTheHour, 'X-RateLimit-Remaining' => '4999']));
        self::assertSame([100, 200, 400], $this->sleeps);

        $this->attempt('rtry:a=4;d=100;j=0', HttpFailure::status(500, ['RateLimit-Reset' => '30']));
        self::assertSame([100, 200, 400], $this->sleeps);
    }

    public function testResetHeadersStillSetTheWaitOnA429OrA503(): void
    {
        $this->attempt('rtry:a=2;d=100;j=0', HttpFailure::status(429, ['RateLimit-Reset' => '5']));
        self::assertSame([5000], $this->sleeps);

        $this->attempt('rtry:a=2;d=100;j=0', HttpFailure::status(503, ['X-RateLimit-Reset-After' => '3']));
        self::assertSame([3000], $this->sleeps);
    }

    public function testRetryAfterIsHonouredOnAnyRetryableStatus(): void
    {
        foreach ([408, 500, 502, 504] as $status) {
            $this->attempt('rtry:a=2;d=100;j=0', HttpFailure::status($status, ['Retry-After' => '2']));
            self::assertSame([2000], $this->sleeps, "status $status");
        }
    }

    public function testAnHttpDateInTheObsoleteFormatsIsStillRead(): void
    {
        $now = intdiv((new FrozenClock())->nowUnixMs(), 1000);
        foreach ([
            gmdate('D, d M Y H:i:s', $now + 3) . ' GMT',
            gmdate('l, d-M-y H:i:s', $now + 3) . ' GMT',
            gmdate('D M j H:i:s Y', $now + 3),
            gmdate('D, d M Y H:i:s', $now + 3) . ' +0000',
        ] as $date) {
            $this->attempt('rtry:a=2;d=100;j=0', HttpFailure::status(503, ['Retry-After' => $date]));
            self::assertSame([3000], $this->sleeps, $date);
        }
    }
}
