<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitBreakerStoreInterface;
use Gohany\Retry\CircuitOpenException;
use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Retry\VersionedValue;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\CircuitTransition;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Tests\Support\HttpFailure;
use PHPUnit\Framework\TestCase;

/**
 * The breaker's optional transition callback (v2.1): it fires once per actual change of
 * state, never per call, and never throws into the caller.
 */
final class BreakerTransitionsTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private FrozenClock $clock;

    /** @var list<string> "key from->to @ms since T0" */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(self::T0);
        $this->seen = [];
    }

    private function record(string $prefix = ''): \Closure
    {
        return function (CircuitTransition $t) use ($prefix): void {
            $this->seen[] = $prefix . sprintf('%s %s->%s @%d', $t->key, $t->from->value, $t->to->value, $t->atUnixMs - self::T0);
        };
    }

    private function breaker(?CircuitBreakerStoreInterface $store = null, ?callable $onTransition = null): CircuitBreaker
    {
        return new CircuitBreaker(
            $store ?? new InMemoryCircuitBreakerStore($this->clock),
            $this->clock,
            failureThreshold: 3,
            windowMs: 10_000,
            openMs: 5_000,
            onTransition: $onTransition ?? $this->record(),
        );
    }

    private function open(CircuitBreaker $breaker, string $key = 'api'): void
    {
        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure($key);
        }
    }

    public function testTheFullCycleReportsEachTransitionOnce(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure('api');
        $breaker->recordFailure('api');
        self::assertSame([], $this->seen, 'failures below the threshold change nothing');

        $this->clock->advanceMs(100);
        $breaker->recordFailure('api');
        $breaker->recordFailure('api');
        self::assertFalse($breaker->allow('api'));
        self::assertSame(['api closed->open @100'], $this->seen, 'one event for the opening failure, none while open');

        $this->clock->advanceMs(5_000);
        self::assertTrue($breaker->allow('api'), 'the probe');
        self::assertFalse($breaker->allow('api'), 'one probe only');
        self::assertSame(['api closed->open @100', 'api open->half_open @5100'], $this->seen);

        $this->clock->advanceMs(20);
        $breaker->recordSuccess('api');
        $breaker->recordSuccess('api');
        self::assertTrue($breaker->allow('api'));
        self::assertSame(
            ['api closed->open @100', 'api open->half_open @5100', 'api half_open->closed @5120'],
            $this->seen
        );
    }

    public function testAFailedProbeReopensTheCircuit(): void
    {
        $breaker = $this->breaker();
        $this->open($breaker);
        $this->clock->advanceMs(5_000);
        self::assertTrue($breaker->allow('api'));
        $this->clock->advanceMs(7);
        $breaker->recordFailure('api');

        self::assertSame(CircuitState::Open, $breaker->state('api'));
        self::assertSame(['api closed->open @0', 'api open->half_open @5000', 'api half_open->open @5007'], $this->seen);
    }

    public function testCallsThatChangeNothingReportNothing(): void
    {
        $breaker = $this->breaker();
        for ($i = 0; $i < 50; $i++) {
            self::assertTrue($breaker->allow('api'));
            $breaker->recordSuccess('api');
            $breaker->state('api');
        }
        $breaker->recordFailure('api');
        $breaker->recordFailure('api');
        $this->clock->advanceMs(10_001);
        $breaker->recordFailure('api'); // the first two slid out of the window

        self::assertSame(CircuitState::Closed, $breaker->state('api'));
        self::assertSame([], $this->seen);
    }

    public function testAReplacedProbeIsNotATransition(): void
    {
        $breaker = $this->breaker();
        $this->open($breaker);
        $this->clock->advanceMs(5_000);
        self::assertTrue($breaker->allow('api'));
        // The probe never reports; after another openMs a new probe replaces it.
        $this->clock->advanceMs(5_000);
        self::assertTrue($breaker->allow('api'));

        self::assertSame(['api closed->open @0', 'api open->half_open @5000'], $this->seen);
    }

    public function testKeysAreReportedSeparately(): void
    {
        $breaker = $this->breaker();
        $this->open($breaker, 'search');
        $breaker->recordFailure('mail');

        self::assertSame(['search closed->open @0'], $this->seen);
        self::assertTrue($breaker->allow('mail'));
    }

    public function testTheTransitionCarriesKeyStatesAndClockTime(): void
    {
        $got = null;
        $breaker = $this->breaker(null, static function (CircuitTransition $t) use (&$got): void {
            $got = $t;
        });
        $this->clock->advanceMs(1_234);
        $this->open($breaker);

        self::assertInstanceOf(CircuitTransition::class, $got);
        self::assertSame(['api', CircuitState::Closed, CircuitState::Open], [$got->key, $got->from, $got->to]);
        self::assertSame(self::T0 + 1_234, $got->atUnixMs);
        self::assertSame((string) (self::T0 + 1_234), $got->at()->format('Uv'));
    }

    public function testAThrowingCallbackIsSwallowedAndTheStateStillChanges(): void
    {
        $calls = 0;
        $breaker = $this->breaker(null, static function () use (&$calls): void {
            $calls++;
            throw new \RuntimeException('listener broke');
        });

        $this->open($breaker);
        self::assertSame(CircuitState::Open, $breaker->state('api'));
        $this->clock->advanceMs(5_000);
        self::assertTrue($breaker->allow('api'));
        $breaker->recordSuccess('api');

        self::assertSame(CircuitState::Closed, $breaker->state('api'));
        self::assertSame(3, $calls, 'called once per transition, even after throwing');
    }

    public function testAnErrorFromTheCallbackIsSwallowedToo(): void
    {
        $breaker = $this->breaker(null, static function (): void {
            throw new \TypeError('listener broke');
        });
        $this->open($breaker);
        self::assertFalse($breaker->allow('api'));
    }

    public function testWithoutACallbackNothingChanges(): void
    {
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, 1, 1_000, 1_000);
        $breaker->recordFailure('api');
        self::assertFalse($breaker->allow('api'));
    }

    /** Two processes sharing a store: each transition is reported by exactly one of them. */
    public function testBreakersSharingAStoreReportEachTransitionOnce(): void
    {
        $store = new InMemoryCircuitBreakerStore($this->clock);
        $a = $this->breaker($store, $this->record('A '));
        $b = $this->breaker($store, $this->record('B '));

        $a->recordFailure('api');
        $b->recordFailure('api');
        $a->recordFailure('api'); // the third failure: A opens it
        $b->recordFailure('api');
        self::assertFalse($a->allow('api'));
        self::assertFalse($b->allow('api'));

        $this->clock->advanceMs(5_000);
        self::assertTrue($b->allow('api')); // B takes the probe
        self::assertFalse($a->allow('api'));
        $b->recordSuccess('api');
        $a->recordSuccess('api');

        self::assertSame(['A api closed->open @0', 'B api open->half_open @5000', 'B api half_open->closed @5000'], $this->seen);
    }

    /**
     * A races B: A reads "open", B moves the key to half-open before A's compare-and-set, so
     * A's write fails, A re-reads and finds a probe already out. Only B reports.
     */
    public function testALostCompareAndSetReportsNothing(): void
    {
        $inner = new InMemoryCircuitBreakerStore($this->clock);
        $racing = new class ($inner) implements CircuitBreakerStoreInterface {
            public ?\Closure $beforeNextWrite = null;

            public function __construct(private readonly CircuitBreakerStoreInterface $inner)
            {
            }

            public function load(string $key): ?VersionedValue
            {
                return $this->inner->load($key);
            }

            public function compareAndSet(string $key, ?int $expectedVersion, string $value, int $ttlMs): bool
            {
                if ($this->beforeNextWrite !== null) {
                    $race = $this->beforeNextWrite;
                    $this->beforeNextWrite = null;
                    $race();
                }
                return $this->inner->compareAndSet($key, $expectedVersion, $value, $ttlMs);
            }
        };
        $a = $this->breaker($racing, $this->record('A '));
        $b = $this->breaker($inner, $this->record('B '));
        $this->open($b);
        $this->clock->advanceMs(5_000);

        $bTookTheProbe = null;
        $racing->beforeNextWrite = static function () use ($b, &$bTookTheProbe): void {
            $bTookTheProbe = $b->allow('api');
        };
        self::assertFalse($a->allow('api'), 'A lost the race for the probe');
        self::assertTrue($bTookTheProbe);

        self::assertSame(['B api closed->open @0', 'B api open->half_open @5000'], $this->seen);
    }

    /**
     * Pinned, documented limit: a key nobody touches for windowMs + 2·openMs expires from the
     * store and reads as closed again, with no transition reported.
     */
    public function testAnExpiredOpenKeyComesBackClosedWithoutATransition(): void
    {
        $breaker = $this->breaker();
        $this->open($breaker);
        $this->clock->advanceMs(10_000 + 2 * 5_000);

        self::assertSame(CircuitState::Closed, $breaker->state('api'));
        self::assertTrue($breaker->allow('api'));
        self::assertSame(['api closed->open @0'], $this->seen);
    }

    /** End to end: a real spec through Retry::try(), recording the sleeps and the transitions. */
    public function testASpecRunRecordsSleepsAndTransitions(): void
    {
        $sleeper = new RecordingSleeper($this->clock);
        $breaker = new CircuitBreaker(
            new InMemoryCircuitBreakerStore($this->clock),
            $this->clock,
            failureThreshold: 2,
            windowMs: 10_000,
            openMs: 5_000,
            onTransition: $this->record(),
        );
        $retry = (new Retry($this->clock, $sleeper, null, SeededRandom::randomizer(1117)))
            ->withGuard('search-api', null, $breaker);
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=5;d=100;j=0;on=5xx');
        $calls = 0;
        $alwaysDown = static function () use (&$calls): never {
            $calls++;
            throw HttpFailure::status(503);
        };

        $first = $retry->tryOutcome($alwaysDown, $policy);
        self::assertSame([2, GiveUpReason::CircuitOpen], [$first->attempts(), $first->giveUpReason()]);
        // The failure that opens the circuit ends the run at once: no 200 ms wait first.
        self::assertSame([100], $sleeper->sleeps());
        self::assertSame(['search-api closed->open @100'], $this->seen);

        try {
            $retry->try($alwaysDown, $policy);
            self::fail('an open circuit must throw');
        } catch (CircuitOpenException) {
        }
        self::assertSame(2, $calls, 'the open circuit never ran the operation');

        $this->clock->advanceMs(5_000);
        self::assertSame('ok', $retry->try(static fn (): string => 'ok', $policy));
        self::assertSame(
            ['search-api closed->open @100', 'search-api open->half_open @5100', 'search-api half_open->closed @5100'],
            $this->seen
        );
        self::assertSame([100], $sleeper->sleeps(), 'the probe ran without sleeping');
    }
}
