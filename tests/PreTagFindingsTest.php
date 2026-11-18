<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptContext;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\LaravelShapedFailure;
use Gohany\Rtry\Tests\Support\StatusAccessorFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * PR #6 re-verification, new findings 1 to 6, settled before the 2.3.0 tag:
 *
 * 1. A 403 that says it is rate limited (Retry-After, or an exhausted X-RateLimit-Remaining
 *    with a reset) retries again, as in 2.2.0; on=RATE_LIMITED matches a 5xx with Retry-After.
 * 2. Laravel's RequestException is judged by its response's status without a reader.
 * 3. The driver errors 2.2.0 retried (PostgreSQL statement and lock timeouts, MongoDB, Oracle,
 *    Node-style errno names, libmemcached, Go) retry again.
 * 4. On the durable path, given the attempt's context, the spread stays inside dl.
 * 5. A wait of exactly the ceiling (a day) is decided by the server's time, not by jitter.
 * 6. Tests for the five mutations that survived the suite.
 *
 * End to end where it matters: parse a real rtry: string, run Retry against an operation that
 * always throws, record the attempts and sleeps.
 */
final class PreTagFindingsTest extends TestCase
{
    private const DEFAULT_SPEC = 'rtry:a=4;d=100;j=0;on=default';

    /** @var list<int> */
    private array $sleeps = [];

    /** @return array{int, ?GiveUpReason} attempts and give-up reason; sleeps land in $this->sleeps */
    private function attempt(string $spec, \Throwable $error, int $seed = 1118): array
    {
        $clock = new FrozenClock();
        $sleeper = new RecordingSleeper($clock);
        $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer($seed));
        $policy = (new RtryPolicyFactory())->fromSpec($spec);

        $result = $retry->tryOutcome(static fn () => throw $error, $policy);

        $this->sleeps = $sleeper->sleeps();
        self::assertSame($error, $result->lastError(), 'the caller always gets its own error back');
        return [$result->attempts(), $result->giveUpReason()];
    }

    // ---- finding 1: a rate-limited 403 retries, as in 2.2.0 --------------------------------

    public function testA403WithRetryAfterRetriesAndWaitsWhatTheServerSaid(): void
    {
        // GitHub's secondary rate limit: 403 with Retry-After.
        $error = HttpFailure::status(403, ['Retry-After' => '60'], 'You have exceeded a secondary rate limit');

        $meta = RuleBasedFailureClassifier::default()->classify($error);
        self::assertSame(403, $meta->getStatusCode());
        self::assertSame(['RATE_LIMITED'], $meta->getTags());

        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $error));
        self::assertSame([60000, 60000, 60000], $this->sleeps);
    }

    public function testA403WithAnExhaustedRateLimitWaitsForTheReset(): void
    {
        // GitHub's primary rate limit: 403 (or 429), X-RateLimit-Remaining: 0, epoch reset.
        // The FrozenClock starts at 1_800_000_000 s; the reset is 30 s later. After the first
        // wait the reset is in the past, so the backoff takes over.
        $github = HttpFailure::status(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1800000030']);
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $github));
        self::assertSame([30000, 200, 400], $this->sleeps);

        // The IETF fields: RateLimit-Remaining: 0 and a delta RateLimit-Reset.
        $ietf = HttpFailure::status(403, ['RateLimit-Remaining' => '0', 'RateLimit-Reset' => '30']);
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $ietf));
        self::assertSame([30000, 30000, 30000], $this->sleeps);
    }

    public function testA403WithRateLimitHeadersButQuotaLeftRunsOnce(): void
    {
        // Sent on every response: a plain 403 is still a 403.
        $error = HttpFailure::status(403, ['X-RateLimit-Remaining' => '4999', 'X-RateLimit-Reset' => '1800000030']);
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $error));

        $noReset = HttpFailure::status(403, ['X-RateLimit-Remaining' => '0']);
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $noReset));
    }

    public function testOnRateLimitedMatchesA5xxWithRetryAfter(): void
    {
        $error = HttpFailure::status(500, ['Retry-After' => '2']);
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=4;d=100;j=0;on=RATE_LIMITED', $error));
        self::assertSame([2000, 2000, 2000], $this->sleeps);

        $exhausted502 = HttpFailure::status(502, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '2']);
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=4;d=100;j=0;on=RATE_LIMITED', $exhausted502));
        self::assertSame([2000, 2000, 2000], $this->sleeps);
    }

    /** @return iterable<string, array{int}> */
    public static function otherClientErrors(): iterable
    {
        foreach ([400, 401, 404, 409, 422] as $status) {
            yield "$status" => [$status];
        }
    }

    #[DataProvider('otherClientErrors')]
    public function testAnyOther4xxWithRetryAfterStillRunsOnce(int $status): void
    {
        $error = HttpFailure::status($status, ['Retry-After' => '2', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '2']);
        self::assertNotContains('RATE_LIMITED', RuleBasedFailureClassifier::default()->classify($error)->getTags());
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $error));
        self::assertSame([], $this->sleeps);
    }

    public function testRateLimitedIsStillNeverTaggedBelow400(): void
    {
        foreach ([200, 302, 399] as $status) {
            $meta = RuleBasedFailureClassifier::default()->classify(
                HttpFailure::status($status, ['Retry-After' => '2', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '2'])
            );
            self::assertSame([], $meta->getTags(), "status $status");
        }
    }

    // ---- finding 2: Laravel's RequestException without a reader -----------------------------

    public function testALaravel422WhoseBodySaysTimedOutRunsOnceWithoutAReader(): void
    {
        $error = LaravelShapedFailure::status(422, [], '{"error":"3DS challenge timed out"}');

        $meta = RuleBasedFailureClassifier::default()->classify($error);
        self::assertSame(422, $meta->getStatusCode());
        self::assertSame([], $meta->getTags());
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $error));
    }

    public function testALaravel401SayingSessionTimedOutRunsOnceWithoutAReader(): void
    {
        $error = LaravelShapedFailure::status(401, [], 'Session timed out. Please log in again.');
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $error));
    }

    public function testALaravel429Or503HonoursRetryAfterWithoutAReader(): void
    {
        foreach ([429, 503] as $status) {
            $error = LaravelShapedFailure::status($status, ['Retry-After' => ['2']], 'slow down');
            self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $error), "status $status");
            self::assertSame([2000, 2000, 2000], $this->sleeps, "status $status");
        }
    }

    public function testAResponsePropertyThatIsNotAnHttpResponseIsIgnored(): void
    {
        // A "response" property without status(), a status() that is not an HTTP status, or one
        // that throws: no response found, so the words decide as for any error without one.
        $shapes = [
            'no status()' => new class () {
                public function headers(): array
                {
                    return [];
                }
            },
            'status() 999' => new class () {
                public function status(): int
                {
                    return 999;
                }
            },
            'status() a string' => new class () {
                public function status(): string
                {
                    return '404';
                }
            },
            'status() throws' => new class () {
                public function status(): int
                {
                    throw new \LogicException('not sent yet');
                }
            },
        ];
        foreach ($shapes as $name => $response) {
            $error = new LaravelShapedFailure($response, 0, 'connection timed out');
            $meta = RuleBasedFailureClassifier::default()->classify($error);
            self::assertNull($meta->getStatusCode(), $name);
            self::assertSame(['ETIMEDOUT'], $meta->getTags(), $name);
        }
    }

    public function testAResponsePropertyWithoutHeadersStillGivesItsStatus(): void
    {
        $response = new class () {
            public function status(): int
            {
                return 404;
            }

            public function headers(): string
            {
                return 'not a list';
            }
        };
        $meta = RuleBasedFailureClassifier::default()->classify(new LaravelShapedFailure($response, 404, 'connection timed out'));
        self::assertSame(404, $meta->getStatusCode());
        self::assertSame([], $meta->getTags());
    }

    // ---- finding 3: driver errors 2.2.0 retried ---------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function driverErrors(): iterable
    {
        yield 'PostgreSQL statement_timeout (57014)' => ['SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout', 'ETIMEDOUT'];
        yield 'PostgreSQL lock_timeout (55P03)' => ['SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout', 'ETIMEDOUT'];
        yield 'MongoDB socket error or timeout' => ['Failed to read 4 bytes: socket error or timeout', 'ETIMEDOUT'];
        yield 'Oracle ORA-12170' => ['ORA-12170: TNS:Connect timeout occurred', 'ETIMEDOUT'];
        yield 'literal ECONNREFUSED' => ['connect ECONNREFUSED 127.0.0.1:6379', 'ECONNREFUSED'];
        yield 'literal ETIMEDOUT' => ['connect ETIMEDOUT 10.0.0.1:443', 'ETIMEDOUT'];
        yield 'literal ECONNRESET' => ['read ECONNRESET', 'ECONNRESET'];
        yield 'libmemcached' => ['A TIMEOUT OCCURRED', 'ETIMEDOUT'];
        yield 'Go i/o timeout' => ['dial tcp 10.0.0.1:443: i/o timeout', 'ETIMEDOUT'];
    }

    #[DataProvider('driverErrors')]
    public function testADriverErrorThat220RetriedRetriesAgain(string $message, string $tag): void
    {
        $error = new \RuntimeException($message);
        self::assertContains($tag, RuleBasedFailureClassifier::default()->classify($error)->getTags());
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $error));
        self::assertSame([100, 200, 400], $this->sleeps);
    }

    public function testAPostgresLockTimeoutIsAlsoALockFailure(): void
    {
        // Like MySQL's "Lock wait timeout exceeded", which on=DEADLOCK already matches.
        $meta = RuleBasedFailureClassifier::default()->classify(
            new \RuntimeException('SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout')
        );
        self::assertContains('DEADLOCK', $meta->getTags());
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function notDriverErrors(): iterable
    {
        yield 'a setting, not a timeout' => [new \RuntimeException('statement timeout is set to 0')];
        yield 'errno name inside a word' => [new \RuntimeException('XECONNREFUSEDX')];
        yield 'validation' => [new \InvalidArgumentException('lock timeout must be positive')];
    }

    #[DataProvider('notDriverErrors')]
    public function testLookAlikesStillRunOnce(\Throwable $error): void
    {
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $error));
    }

    // ---- finding 4: the durable spread stays inside dl ---------------------------------------

    public function testTheDurableSpreadNeverCrossesTheDeadlineWhenTheServersTimeFits(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100;dl=2050ms');
        $clock = new FrozenClock();
        $scheduler = new RtryScheduler($clock);
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, 2050);
        $error = HttpFailure::status(503, ['Retry-After' => '2']);

        $delays = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $outcome = RtryAttemptOutcome::fromError($error, null, $clock, $policy, 1, SeededRandom::randomizer($seed), $context);
            $decision = $scheduler->next($policy, $outcome, $context);
            self::assertTrue($decision->retry(), "seed $seed gave up: " . $decision->giveUpReason()?->value);
            $delays[] = $decision->delayMs();
        }
        self::assertGreaterThanOrEqual(2000, min($delays));
        self::assertLessThanOrEqual(2049, max($delays));
        self::assertGreaterThan(1, count(array_unique($delays)), 'still spread');
    }

    public function testTheDurableSpreadStillAppliesWithoutADeadline(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100');
        $clock = new FrozenClock();
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, null);
        $error = HttpFailure::status(503, ['Retry-After' => '2']);

        $delays = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $outcome = RtryAttemptOutcome::fromError($error, null, $clock, $policy, 1, SeededRandom::randomizer($seed), $context);
            $delays[] = (new RtryScheduler($clock))->next($policy, $outcome, $context)->delayMs();
        }
        self::assertGreaterThanOrEqual(2000, min($delays));
        self::assertLessThanOrEqual(2100, max($delays));
        self::assertGreaterThan(2049, max($delays), 'no deadline, so the whole window is used');
    }

    // ---- finding 5: a wait of exactly the ceiling ------------------------------------------

    public function testRetryAfterOfExactlyADayBehavesTheSameWithAndWithoutJitter(): void
    {
        $error = HttpFailure::status(503, ['Retry-After' => '86400']);
        for ($seed = 1; $seed <= 50; $seed++) {
            self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100', $error, $seed), "seed $seed");
            self::assertSame([86_400_000], $this->sleeps, "seed $seed");
        }
        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0', $error));
        self::assertSame([86_400_000], $this->sleeps);

        // Just under the ceiling: spread, but never past it.
        $under = HttpFailure::status(503, ['Retry-After' => '86399.95']);
        for ($seed = 1; $seed <= 50; $seed++) {
            $this->attempt('rtry:a=2;d=100', $under, $seed);
            self::assertGreaterThanOrEqual(86_399_950, $this->sleeps[0], "seed $seed");
            self::assertLessThanOrEqual(86_400_000, $this->sleeps[0], "seed $seed");
        }
    }

    public function testRetryAfterOverADayGivesUpWithAndWithoutJitter(): void
    {
        $error = HttpFailure::status(503, ['Retry-After' => '86401']);
        foreach (['rtry:a=2;d=100', 'rtry:a=2;d=100;j=0'] as $spec) {
            self::assertSame([1, GiveUpReason::DeadlineExceeded], $this->attempt($spec, $error), $spec);
        }
    }

    public function testTheDurablePathDecidesADayByTheServersTimeToo(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=100');
        $clock = new FrozenClock();
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, null);
        $error = HttpFailure::status(503, ['Retry-After' => '86400']);

        for ($seed = 1; $seed <= 50; $seed++) {
            $outcome = RtryAttemptOutcome::fromError($error, null, $clock, $policy, 1, SeededRandom::randomizer($seed), $context);
            $decision = (new RtryScheduler($clock))->next($policy, $outcome, $context);
            self::assertSame(86_400_000, $decision->delayMs(), "seed $seed");
        }

        // A durable caller with a longer ceiling passes it, and the spread is kept under that.
        $week = 7 * 86_400_000;
        $long = HttpFailure::status(503, ['Retry-After' => (string) (7 * 86400)]);
        for ($seed = 1; $seed <= 20; $seed++) {
            $outcome = RtryAttemptOutcome::fromError($long, null, $clock, $policy, 1, SeededRandom::randomizer($seed), $context, $week);
            $decision = (new RtryScheduler($clock, null, $week))->next($policy, $outcome, $context);
            self::assertSame($week, $decision->delayMs(), "seed $seed");
        }
    }

    // ---- finding 6: the five surviving mutations --------------------------------------------

    public function testAnErrorIsNeverTaggedFromItsMessage(): void
    {
        foreach ([new \TypeError('connection timed out'), new \ValueError('Connection refused'), new \Error('deadlock found')] as $error) {
            self::assertSame([], RuleBasedFailureClassifier::default()->classify($error)->getTags(), $error::class);
            self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $error));
        }
        // ...but the transport error it wraps still counts.
        $wrapped = new \Error('bad state', 0, new \RuntimeException('connection timed out'));
        self::assertSame(['ETIMEDOUT'], RuleBasedFailureClassifier::default()->classify($wrapped)->getTags());
    }

    public function testAPsr18NetworkExceptionIsATransportError(): void
    {
        // cURL error 6 is not in Rtry's number list, and nothing in the message is a phrase.
        $request = $this->createStub(RequestInterface::class);
        $error = new class ('cURL error 6: Could not resolve host: api.example.test', $request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        self::assertSame(['NETWORK_ERROR'], RuleBasedFailureClassifier::default()->classify($error)->getTags());
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $error));

        $wrapped = new \RuntimeException('sync failed', 0, $error);
        self::assertSame(['NETWORK_ERROR'], RuleBasedFailureClassifier::default()->classify($wrapped)->getTags());
    }

    public function testASymfonyTransportExceptionIsATransportError(): void
    {
        $error = new class ('Could not resolve host: api.example.test') extends \RuntimeException implements TransportExceptionInterface {
        };

        self::assertSame(['NETWORK_ERROR'], RuleBasedFailureClassifier::default()->classify($error)->getTags());
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $error));
    }

    public function testAStatusAccessorAbove599IsNoResponse(): void
    {
        // Not an HTTP status, so no response was found and the transport phrase decides.
        foreach ([600, 999] as $status) {
            $error = new StatusAccessorFailure($status, 'connection timed out', 'getStatusCode');
            $meta = RuleBasedFailureClassifier::default()->classify($error);
            self::assertNull($meta->getStatusCode(), "status $status");
            self::assertSame(['ETIMEDOUT'], $meta->getTags(), "status $status");
            self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $error));
        }
    }

    public function testTheSpreadAboveAServerTimeIsCapped(): void
    {
        // Nominal backoff for attempt 2 is 10 s; the cap holds it to 100 ms, so the spread does too.
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=10s;cap=100ms;j=100%@full');
        $spreads = [];
        for ($seed = 1; $seed <= 50; $seed++) {
            $spreads[] = RtryScheduler::headerSpreadMs($policy, 1, SeededRandom::randomizer($seed));
        }
        self::assertLessThanOrEqual(100, max($spreads));
        self::assertGreaterThan(0, max($spreads));

        $error = HttpFailure::status(503, ['Retry-After' => '2']);
        for ($seed = 1; $seed <= 20; $seed++) {
            $this->attempt('rtry:a=2;d=10s;cap=100ms;j=100%@full', $error, $seed);
            self::assertGreaterThanOrEqual(2000, $this->sleeps[0], "seed $seed");
            self::assertLessThanOrEqual(2100, $this->sleeps[0], "seed $seed");
        }
    }

    public function testAnXRateLimitResetFrom10To9IsAnEpochTime(): void
    {
        // The FrozenClock is at 1_800_000_000 s: a reset 30 s on is an epoch time, not a
        // 57-year delta (which would give up).
        $epoch = HttpFailure::status(429, ['X-RateLimit-Reset' => '1800000030']);
        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0', $epoch));
        self::assertSame([30000], $this->sleeps);

        // Just below 10^9 is still a delta: about 31 years, so it gives up.
        $delta = HttpFailure::status(429, ['X-RateLimit-Reset' => '999999999']);
        self::assertSame([1, GiveUpReason::DeadlineExceeded], $this->attempt('rtry:a=2;d=100;j=0', $delta));
    }
}
