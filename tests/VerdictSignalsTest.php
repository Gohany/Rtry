<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Retry\Testing\TestOutcome;
use Gohany\Rtry\Contracts\BreakerVerdictInterface;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptContext;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicy;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Impl\Rules\InstanceOfRule;
use Gohany\Rtry\Impl\Rules\MessageRegexRule;
use Gohany\Rtry\Tests\Support\ForeignPolicy;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\RecordingBreaker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The hostile review of PR #7 (head 06e933d): a status counts for the verdict only when it was
 * read from a response; a verdict can say "retried, or unavailable"; outages the classifier
 * does not tag still count; the verdict never changes the scheduler's decision; and the six
 * edges that survived mutation are pinned.
 */
final class VerdictSignalsTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private FrozenClock $clock;
    private RecordingSleeper $sleeper;
    /** @var list<array{string,string,array<string,mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(self::T0);
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->logs = [];
    }

    private function retry(int $seed = 1118): Retry
    {
        $sink = function (string $level, string $message, array $context): void {
            $this->logs[] = [$level, $message, $context];
        };
        $logger = new class ($sink) extends AbstractLogger {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->sink)((string) $level, (string) $message, $context);
            }
        };
        return new Retry($this->clock, $this->sleeper, $logger, SeededRandom::randomizer($seed));
    }

    private function breaker(int $threshold = 2): CircuitBreaker
    {
        return new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, failureThreshold: $threshold, windowMs: 10_000, openMs: 5_000);
    }

    /** A breaker with $threshold failures recorded, now half-open with its probe to send. */
    private function halfOpen(int $threshold = 2): CircuitBreaker
    {
        $breaker = $this->breaker($threshold);
        for ($i = 0; $i < $threshold; $i++) {
            $breaker->recordFailure('api');
        }
        $this->clock->advanceMs(5_000);
        return $breaker;
    }

    private static function classified(\Throwable $e, ?RuleBasedFailureClassifier $classifier = null): RtryAttemptOutcome
    {
        return RtryAttemptOutcome::fromMetadata($e, ($classifier ?? RuleBasedFailureClassifier::default())->classify($e), self::T0);
    }

    private static function pdo(string $message, int $code = 0, ?array $errorInfo = null): \PDOException
    {
        $e = new \PDOException($message, $code);
        $e->errorInfo = $errorInfo;
        return $e;
    }

    // ---- blocker: an exception code is never the service's status --------------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function codesThatAreNotStatuses(): iterable
    {
        yield 'validation, 500' => [new \InvalidArgumentException('amount must be positive', 500)];
        yield 'domain, 503' => [new \DomainException('Unsupported currency XYZ', 503)];
        yield 'SMTP 550' => [new \RuntimeException('550 5.1.1 User unknown', 550)];
        yield 'AMQP 530' => [new \RuntimeException('NOT_ALLOWED - vhost /x not found', 530)];
    }

    #[DataProvider('codesThatAreNotStatuses')]
    public function testAnExceptionCodeNeverOpensTheCircuit(\Throwable $error): void
    {
        $breaker = $this->breaker();
        $calls = 0;
        $op = static function () use (&$calls, $error): never {
            $calls++;
            throw $error;
        };
        $retry = $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict());
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;on=429,ETIMEDOUT,ECONNREFUSED');

        for ($i = 0; $i < 3; $i++) {
            $result = $retry->tryOutcome($op, $policy);
            self::assertSame(GiveUpReason::NotRetryable, $result->giveUpReason());
            self::assertSame($error, $result->lastError());
        }

        self::assertSame(3, $calls, 'no call is refused');
        self::assertSame(CircuitState::Closed, $breaker->state('api'));
    }

    #[DataProvider('codesThatAreNotStatuses')]
    public function testTheVerdictReadsNoStatusFromAnExceptionCode(\Throwable $error): void
    {
        $outcome = self::classified($error);
        self::assertNotNull($outcome->statusCode(), 'the policy still matches the code, as in 2.x');
        self::assertFalse($outcome->statusFromResponse());
        self::assertFalse((new ServiceFailureVerdict())->isServiceFailure($outcome, false));
    }

    public function testALogicExceptionWithA5xxCodeNeverCountsEvenWhenItsMessageNamesAnOutage(): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        $e = new \LogicException('connection refused: could not resolve host', 503);

        self::assertFalse($verdict->isServiceFailure(self::classified($e), false));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure($e, 503), false));
        self::assertFalse($verdict->isServiceFailure(self::classified(new \TypeError('connection timed out', 504)), false));
    }

    public function testAStatusReadFromAResponseCountsAtAnyDepth(): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        $deep = new \RuntimeException('client failed', 0, new \RuntimeException('wrapped', 0, HttpFailure::status(503)));

        self::assertTrue($verdict->isServiceFailure(self::classified($deep), false));
        self::assertTrue($verdict->isServiceFailure(self::classified(HttpFailure::status(500)), false));
        self::assertFalse($verdict->isServiceFailure(self::classified(HttpFailure::status(404)), false));
    }

    public function testAnOutcomeThatDoesNotSayWhereItsStatusCameFromIsCheckedAgainstTheError(): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);

        // RtryAttemptOutcome::failure() without saying: the verdict looks for a response itself.
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x', 503), 503), false));
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(HttpFailure::status(503), 503), false));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(HttpFailure::status(404), 503), false), 'the response, not the claim');
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), 503, [], null, true), false));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(HttpFailure::status(503), 503, [], null, false), false));

        // Another library's outcome: same.
        self::assertFalse($verdict->isServiceFailure(TestOutcome::failure(503), false));
        self::assertTrue($verdict->isServiceFailure(TestOutcome::failure(503, [], null, HttpFailure::status(503)), false));
    }

    public function testARulesStatusOverAResponsesIsNotFromTheResponse(): void
    {
        $classifier = RuleBasedFailureClassifier::default()->addRule(new MessageRegexRule('~busy~', 503));
        $outcome = self::classified(HttpFailure::status(404, [], 'busy'), $classifier);

        self::assertSame(503, $outcome->statusCode());
        self::assertFalse($outcome->statusFromResponse());
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false));
        self::assertTrue(self::classified(HttpFailure::status(503))->statusFromResponse());
    }

    public function testACustomClassifiersMetadataLeavesTheSourceUnknown(): void
    {
        $outcome = RtryAttemptOutcome::fromMetadata(new \RuntimeException('x'), new class implements \Gohany\Rtry\Contracts\FailureMetadataInterface {
            public function getStatusCode(): ?int
            {
                return 502;
            }

            public function getTags(): array
            {
                return [];
            }

            public function getContextPatch(): array
            {
                return [];
            }

            public function getMinNextDelayMs(): ?int
            {
                return null;
            }

            public function getNotBeforeUnixMs(): ?int
            {
                return null;
            }

            public function getHeaders(): array
            {
                return [];
            }
        }, self::T0);

        self::assertNull($outcome->statusFromResponse());
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false));
        self::assertTrue(RtryAttemptOutcome::fromMetadata(new \RuntimeException('x'), new FailureMetadata(502, fromResponse: true), self::T0)->statusFromResponse());
    }

    // ---- should-fix 1: the verdict never changes the scheduler's decision -------------------

    /** @return iterable<string, array{\Closure(): \Throwable, string}> */
    public static function decisionCases(): iterable
    {
        yield '503 with Retry-After' => [static fn () => HttpFailure::status(503, ['Retry-After' => '2']), 'rtry:a=4;d=100'];
        yield 'retried 404' => [static fn () => HttpFailure::status(404), 'rtry:a=4;d=100;on=default,404'];
        yield 'refused under on=5xx' => [static fn () => new \RuntimeException('connect ECONNREFUSED 10.0.0.7:443'), 'rtry:a=4;d=100;on=5xx'];
        yield 'deadlock' => [static fn () => new \RuntimeException('SQLSTATE[40001]: Deadlock found'), 'rtry:a=4;d=100;dl=1s'];
        yield 'validation code 500' => [static fn () => new \InvalidArgumentException('bad', 500), 'rtry:a=3;d=50'];
        yield 'DNS failure' => [static fn () => new \RuntimeException('cURL error 6: Could not resolve host: api.example.test'), 'rtry:a=3;d=50'];
    }

    /** @param \Closure(): \Throwable $error */
    #[DataProvider('decisionCases')]
    public function testTheVerdictNeverChangesTheSchedulersDecisionForAnAttempt(\Closure $error, string $spec): void
    {
        $verdicts = [
            'none' => null,
            'always' => self::fixedVerdict(true),
            'never' => self::fixedVerdict(false),
            'service' => new ServiceFailureVerdict(),
            'service only' => new ServiceFailureVerdict(countRetryable: false),
        ];
        $runs = [];
        foreach ($verdicts as $name => $verdict) {
            $this->setUp();
            $breaker = new RecordingBreaker();
            $result = $this->retry()->withGuard('api', null, $breaker, $verdict)
                ->tryOutcome(static fn (): never => throw $error(), (new RtryPolicyFactory())->fromSpec($spec));
            $runs[$name] = [$result->attempts(), $result->giveUpReason(), $this->sleeper->sleeps()];
            self::assertCount($result->attempts(), $breaker->reports, $name . ': one report per attempt');
        }

        foreach ($runs as $name => $run) {
            self::assertSame($runs['none'], $run, $name);
        }
    }

    public function testThroughTheBreakerTheVerdictChangesWhetherTheRunGoesOn(): void
    {
        // A 404 retried under on=default,404: without a verdict two failures open the circuit
        // and end the run; a verdict that calls the 404 a success lets the policy run out.
        $op = static fn (): never => throw HttpFailure::status(404);
        $spec = 'rtry:a=4;d=100;j=0;on=default,404';

        $without = $this->retry()->withGuard('api', null, $this->breaker())->tryOutcome($op, (new RtryPolicyFactory())->fromSpec($spec));
        self::assertSame([2, GiveUpReason::CircuitOpen, [100]], [$without->attempts(), $without->giveUpReason(), $this->sleeper->sleeps()]);

        $this->setUp();
        $with = $this->retry()->withGuard('api', null, $this->breaker(), new ServiceFailureVerdict(countRetryable: false))
            ->tryOutcome($op, (new RtryPolicyFactory())->fromSpec($spec));
        self::assertSame([4, GiveUpReason::AttemptsExhausted, [100, 200, 400]], [$with->attempts(), $with->giveUpReason(), $this->sleeper->sleeps()]);
    }

    // ---- should-fix 2: retried, or unavailable ---------------------------------------------

    public function testTheVerdictIsToldWhetherThePolicyRetriesTheFailure(): void
    {
        $verdict = new class implements BreakerVerdictInterface {
            /** @var list<array{?int, bool}> */
            public array $seen = [];

            public function isServiceFailure(AttemptOutcomeInterface $outcome, bool $retryable): bool
            {
                $this->seen[] = [$outcome->statusCode(), $retryable];
                return false;
            }
        };
        $statuses = [503, 503, 404];
        $op = static function () use (&$statuses): never {
            throw HttpFailure::status(array_shift($statuses));
        };
        $this->retry()->withGuard('api', null, $this->breaker(50), $verdict)->tryOutcome($op, (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;on=5xx'));

        $this->setUp();
        $this->retry()->withGuard('api', null, $this->breaker(50), $verdict)->tryOutcome(static fn (): never => throw HttpFailure::status(502), (new RtryPolicyFactory())->fromSpec('rtry:a=1;d=100;on=5xx'));

        self::assertSame([[503, true], [503, true], [404, false], [502, true]], $verdict->seen, 'out of attempts is still retryable');
    }

    public function testARetriedFailureTheVerdictDoesNotListStillCountsByDefault(): void
    {
        // The reviewer's case: a caller's own tag for an outage the verdict does not know.
        $classifier = RuleBasedFailureClassifier::default()->addRule(new MessageRegexRule('~upstream is down~', null, ['UPSTREAM_DOWN']));
        $policy = (new RtryPolicyFactory(null, $classifier))->fromSpec('rtry:a=3;d=100;j=0;on=default,UPSTREAM_DOWN');
        $op = function () use (&$calls): never {
            $calls++;
            throw new \RuntimeException('upstream is down');
        };

        $runs = [];
        foreach (['none' => null, 'service' => new ServiceFailureVerdict(), 'service only' => new ServiceFailureVerdict(countRetryable: false)] as $name => $verdict) {
            $this->setUp();
            $calls = 0;
            $breaker = $this->breaker(3);
            $retry = $this->retry()->withGuard('api', null, $breaker, $verdict);
            $reasons = [];
            for ($i = 0; $i < 3; $i++) {
                $reasons[] = $retry->tryOutcome($op, $policy)->giveUpReason();
            }
            $runs[$name] = [$calls, $reasons, $breaker->state('api')];
        }

        $opened = [3, [GiveUpReason::AttemptsExhausted, GiveUpReason::CircuitOpen, GiveUpReason::CircuitOpen], CircuitState::Open];
        self::assertSame($opened, $runs['none']);
        self::assertSame($opened, $runs['service'], 'the default verdict never reports less than no verdict');
        self::assertSame([9, array_fill(0, 3, GiveUpReason::AttemptsExhausted), CircuitState::Closed], $runs['service only'], 'opt-in: the service\'s answer alone');
    }

    public function testTheDefaultVerdictCountsARetryableFailureAndOnlyAddsToIt(): void
    {
        $verdict = new ServiceFailureVerdict();
        $answered = RtryAttemptOutcome::failure(HttpFailure::status(404), 404);

        self::assertTrue($verdict->isServiceFailure($answered, true));
        self::assertFalse($verdict->isServiceFailure($answered, false));
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($answered, true));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::success('ok'), true), 'a success is never a failure');
    }

    // ---- should-fix 3: outages the classifier does not tag ---------------------------------

    /** @return iterable<string, array{\Closure(): \Throwable}> */
    public static function untaggedOutages(): iterable
    {
        yield 'cURL 6, DNS' => [static fn () => new \RuntimeException('cURL error 6: Could not resolve host: api.example.test (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)')];
        yield 'cURL 5, proxy DNS' => [static fn () => new \RuntimeException('cURL error 5: Could not resolve proxy: proxy.internal')];
        yield 'getaddrinfo' => [static fn () => new \RuntimeException('php_network_getaddresses: getaddrinfo for db.internal failed: Name or service not known')];
        yield 'temporary DNS failure' => [static fn () => new \RuntimeException('getaddrinfo EAI_AGAIN api.example.test')];
        yield 'ENOTFOUND' => [static fn () => new \RuntimeException('getaddrinfo ENOTFOUND api.example.test')];
        yield 'MySQL gone away' => [static fn () => self::pdo('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')];
        yield 'MySQL 2002 by message' => [static fn () => new \RuntimeException('SQLSTATE[HY000] [2002] No such file or directory')];
        yield 'MySQL 2002 by driver code' => [static fn () => self::pdo('No such file or directory', 2002)];
        yield 'MySQL 2006 by errorInfo' => [static fn () => self::pdo('General error', 0, ['HY000', 2006, 'gone'])];
        yield 'MySQL 1040' => [static fn () => self::pdo('SQLSTATE[HY000] [1040] Too many connections', 1040)];
        yield 'MySQL lost connection' => [static fn () => self::pdo('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query')];
        yield 'SQLSTATE class 08' => [static fn () => self::pdo('SQLSTATE[08006] [7] connection to server at "10.0.0.5", port 5432 failed')];
        yield 'PostgreSQL closed' => [static fn () => new \RuntimeException('server closed the connection unexpectedly')];
        yield 'PostgreSQL too many clients' => [static fn () => new \RuntimeException('FATAL: sorry, too many clients already')];
        yield 'phpredis read error' => [static fn () => new \RuntimeException('read error on connection to 10.0.0.9:6379')];
        yield 'broken pipe' => [static fn () => new \RuntimeException('fwrite(): Send of 512 bytes failed with errno=32 Broken pipe')];
        yield 'cURL 60, expired certificate' => [static fn () => new \RuntimeException('cURL error 60: SSL certificate problem: certificate has expired')];
        yield 'cURL 35 by number' => [static fn () => new \RuntimeException('cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL')];
        yield 'host unreachable errno' => [static fn () => new \RuntimeException('connect EHOSTUNREACH 10.0.0.7:443')];
        yield 'wrapped' => [static fn () => new \RuntimeException('query failed', 0, self::pdo('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'))];
        yield 'under a LogicException' => [static fn () => new \LogicException('could not save', 0, new \RuntimeException('read error on connection to 10.0.0.9:6379'))];
    }

    /** @param \Closure(): \Throwable $error */
    #[DataProvider('untaggedOutages')]
    public function testAnUntaggedOutageCounts(\Closure $error): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        self::assertTrue($verdict->isServiceFailure(self::classified($error()), false));
        self::assertTrue($verdict->isServiceFailure(TestOutcome::failure(null, [], null, $error()), false), 'whatever classified it');
    }

    /** @param \Closure(): \Throwable $error */
    #[DataProvider('untaggedOutages')]
    public function testAHalfOpenProbeThatFailsWithAnUntaggedOutageReopensTheCircuit(\Closure $error): void
    {
        $breaker = $this->halfOpen();

        $result = $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict())
            ->tryOutcome(static fn (): never => throw $error(), (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100'));

        self::assertSame(1, $result->attempts());
        self::assertSame(CircuitState::Open, $breaker->state('api'));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function notOutages(): iterable
    {
        yield 'validation naming a host' => [new \InvalidArgumentException('could not resolve host for webhook URL')];
        yield 'type error' => [new \TypeError('broken pipe expected string')];
        yield 'payment refused' => [new \RuntimeException('Payment refused by issuer')];
        yield 'deadlock' => [new \RuntimeException('SQLSTATE[40001]: Deadlock found when trying to get lock')];
        yield 'duplicate key' => [self::pdo('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 0, ['23000', 1062, 'dup'])];
        yield 'another cURL error' => [new \RuntimeException('cURL error 3: URL using bad/illegal format')];
        yield 'a 404 whose body says broken pipe' => [HttpFailure::status(404, [], 'broken pipe: could not resolve host')];
        yield 'a number like a MySQL code' => [new \RuntimeException('invoice 2006 not found', 2006)];
        yield 'no message' => [new \RuntimeException('')];
    }

    #[DataProvider('notOutages')]
    public function testWhatIsNotAnOutage(\Throwable $error): void
    {
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure(self::classified($error), false));
    }

    public function testTransportTypesCount(): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        $network = new class ('x') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                throw new \LogicException('not needed');
            }
        };

        self::assertTrue($verdict->isServiceFailure(TestOutcome::failure(null, [], null, $network), false));
        self::assertTrue($verdict->isServiceFailure(TestOutcome::failure(null, [], null, new \RuntimeException('wrapped', 0, $network)), false));
    }

    // ---- should-fix 5: the six surviving mutations -----------------------------------------

    public function testAVerdictThatThrowsOnAFailureThePolicyDoesNotRetryReportsASuccess(): void
    {
        $verdict = new class implements BreakerVerdictInterface {
            public function isServiceFailure(AttemptOutcomeInterface $outcome, bool $retryable): bool
            {
                throw new \TypeError('boom');
            }
        };
        $breaker = new RecordingBreaker();

        $this->retry()->withGuard('api', null, $breaker, $verdict)
            ->tryOutcome(static fn (): never => throw HttpFailure::status(404), (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;on=5xx'));

        self::assertSame(['recordSuccess'], $breaker->reports);
    }

    public function testAHalfOpenProbeThatFailsWithA404TheVerdictCallsASuccessClosesTheCircuit(): void
    {
        $breaker = $this->halfOpen();

        $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict())
            ->tryOutcome(static fn (): never => throw HttpFailure::status(404), (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100'));

        self::assertSame(CircuitState::Closed, $breaker->state('api'));
    }

    public function testASecondGuardWithoutAVerdictDropsTheFirstOnesVerdict(): void
    {
        $verdict = self::fixedVerdict(true);
        $breaker = new RecordingBreaker();

        $this->retry()->withGuard('api', null, $breaker, $verdict)->withGuard('api', null, $breaker)
            ->tryOutcome(static fn (): never => throw HttpFailure::status(404), (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100'));

        self::assertSame(0, $verdict->asked);
        self::assertSame(['recordSuccess'], $breaker->reports);
    }

    public function testAnOutcomesTagIsMatchedWithoutCase(): void
    {
        $verdict = new ServiceFailureVerdict(['SDK_TRANSPORT'], countRetryable: false);

        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['sdk_transport']), false));
        self::assertTrue((new ServiceFailureVerdict())->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['econnrefused']), false));
    }

    public function testTheSchedulersOutcomeDrawsFromTheSchedulersRandomizer(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=1s');
        $context = new RtryAttemptContext(1, $policy->attempts(), 0, 0, null);
        $error = HttpFailure::status(503, ['Retry-After' => '5']);
        $notBefore = [];
        for ($i = 0; $i < 2; $i++) {
            $scheduler = new RtryScheduler(new FrozenClock(self::T0), SeededRandom::randomizer(42));
            $notBefore[] = $scheduler->outcome($error, $policy, $context)->notBeforeUnixMs();
        }

        self::assertSame($notBefore[0], $notBefore[1]);
        self::assertGreaterThan(self::T0 + 5_000, $notBefore[0]);
    }

    public function testTheSchedulersOutcomeWorksWithAPolicyThatIsNotAnRtryPolicy(): void
    {
        $policy = new ForeignPolicy((new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0'));
        $context = new RtryAttemptContext(1, 3, 0, 0, null);
        $scheduler = new RtryScheduler(new FrozenClock(self::T0));

        $outcome = $scheduler->outcome(HttpFailure::status(503), $policy, $context);

        self::assertSame(503, $outcome->statusCode());
        self::assertSame(100, $scheduler->next($policy, $outcome, $context)->delayMs());
    }

    // ---- nits -----------------------------------------------------------------------------

    public function testATagThatIsNotAStringStillCounts(): void
    {
        $classifier = RuleBasedFailureClassifier::default()->addRule(new InstanceOfRule(\RuntimeException::class, null, [1213, 'ECONNREFUSED']));
        $policy = (new RtryPolicyFactory(null, $classifier))->fromSpec('rtry:a=3;d=100;j=0;on=5xx');
        $breaker = $this->breaker();
        $retry = $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict());

        for ($i = 0; $i < 2; $i++) {
            $retry->tryOutcome(static fn (): never => throw new \RuntimeException('x'), $policy);
        }

        self::assertSame(CircuitState::Open, $breaker->state('api'));
        self::assertSame([], array_filter($this->logs, static fn (array $l): bool => $l[1] === 'retry.guard_error'));
        self::assertTrue((new ServiceFailureVerdict([503]))->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['503']), false));
    }

    public function testAFailureTheVerdictCountsIsLoggedAtWarningEvenWhenThePolicyDoesNotRetryIt(): void
    {
        $op = static fn (): never => throw new \RuntimeException('connect ECONNREFUSED 10.0.0.7:443');
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;on=5xx');

        $this->retry()->withGuard('api', null, $this->breaker(1), new ServiceFailureVerdict())->tryOutcome($op, $policy);
        $with = array_values(array_filter($this->logs, static fn (array $l): bool => $l[1] === 'retry.failure'));
        $this->setUp();
        $this->retry()->withGuard('api', null, $this->breaker(1))->tryOutcome($op, $policy);
        $without = array_values(array_filter($this->logs, static fn (array $l): bool => $l[1] === 'retry.failure'));

        self::assertSame('warning', $with[0][0]);
        self::assertSame('debug', $without[0][0], 'unchanged without a verdict');
    }

    /** @return iterable<string, array{string, \Closure(): \Throwable}> */
    public static function durableAgainstRetry(): iterable
    {
        yield 'no Retry-After' => ['rtry:a=4;d=100', static fn () => HttpFailure::status(503)];
        yield 'fh=0' => ['rtry:a=4;d=100;fh=0', static fn () => HttpFailure::status(503, ['Retry-After' => '1'])];
        yield 'Retry-After followed' => ['rtry:a=4;d=100', static fn () => HttpFailure::status(503, ['Retry-After' => '1'])];
    }

    /** @param \Closure(): \Throwable $error */
    #[DataProvider('durableAgainstRetry')]
    public function testTheDurablePathGivesRetrysDelaysForTheSameSeed(string $spec, \Closure $error): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec($spec);
        foreach ([1, 7, 1118] as $seed) {
            $this->setUp();
            $this->retry($seed)->tryOutcome(static fn (): never => throw $error(), $policy);
            $inProcess = $this->sleeper->sleeps();

            $scheduler = new RtryScheduler($this->clock, SeededRandom::randomizer($seed));
            $durable = [];
            for ($attempt = 1; ; $attempt++) {
                $context = new RtryAttemptContext($attempt, $policy->attempts(), 0, 0, null);
                $decision = $scheduler->next($policy, $scheduler->outcome($error(), $policy, $context), $context);
                if (!$decision->retry()) {
                    break;
                }
                $durable[] = $decision->delayMs();
            }

            self::assertSame($inProcess, $durable, "seed $seed");
        }
    }

    private static function fixedVerdict(bool $answer): BreakerVerdictInterface
    {
        return new class ($answer) implements BreakerVerdictInterface {
            public int $asked = 0;

            public function __construct(private readonly bool $answer)
            {
            }

            public function isServiceFailure(AttemptOutcomeInterface $outcome, bool $retryable): bool
            {
                $this->asked++;
                return $this->answer;
            }
        };
    }
}

