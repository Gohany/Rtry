<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Retry\Testing\TestOutcome;
use Gohany\Rtry\Contracts\ErrorResponse;
use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\Breaker\UnavailableEvidence;
use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Impl\Rules\InstanceOfRule;
use Gohany\Rtry\Impl\Rules\MethodStatusRule;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\RecordingBreaker;
use Gohany\Rtry\Tests\Support\SymfonyShapedFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The re-verification of PR #7 (head 3dfe08b, v2.4.0): findings A to E, fixed in 2.4.1.
 *
 * A: statusFromResponse() false is not "no response": the verdict reads the response itself.
 * B: a database error is not read for outage words when its SQLSTATE says the database answered
 *    (it echoes the values it was sent), and a MySQL code in a message ends at a digit boundary.
 * C: a lock wait timeout is contention, like a deadlock: the database answered.
 * E: the edges that survived mutation, and each entry of UnavailableEvidence's lists alone.
 */
final class ReVerifyFindingsTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private FrozenClock $clock;
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(self::T0);
        $this->sleeper = new RecordingSleeper($this->clock);
    }

    private function retry(): Retry
    {
        return new Retry($this->clock, $this->sleeper, null, SeededRandom::randomizer(241));
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

    private static function classified(\Throwable $e, ?FailureClassifierInterface $classifier = null): RtryAttemptOutcome
    {
        return RtryAttemptOutcome::fromMetadata($e, ($classifier ?? RuleBasedFailureClassifier::default())->classify($e), self::T0);
    }

    /** @param array<int, int|string>|null $errorInfo */
    private static function pdo(string $message, int $code = 0, ?array $errorInfo = null): \PDOException
    {
        $e = new \PDOException($message, $code);
        $e->errorInfo = $errorInfo;
        return $e;
    }

    /** A FailureClassifierInterface that is not Rtry's: the status of a PSR-7 response, nothing else. */
    private static function psr7StatusClassifier(): FailureClassifierInterface
    {
        return new class implements FailureClassifierInterface {
            public function classify(\Throwable $e): FailureMetadataInterface
            {
                return new FailureMetadata($e instanceof HttpFailure ? $e->getResponse()->getStatusCode() : null);
            }
        };
    }

    // ---- A: a real 503 on the error counts, whatever the classifier ------------------------

    /** @return iterable<string, array{\Closure(): FailureClassifierInterface, \Closure(): \Throwable}> */
    public static function realServiceFailuresUnderOtherClassifiers(): iterable
    {
        yield 'MethodStatusRule alone, Symfony-shaped 503 (status from getCode())' => [
            static fn () => new RuleBasedFailureClassifier([new MethodStatusRule()]),
            static fn () => new SymfonyShapedFailure(503),
        ];
        yield 'a FailureClassifierInterface of its own, PSR-7 503' => [
            static fn () => self::psr7StatusClassifier(),
            static fn () => HttpFailure::status(503),
        ];
        yield 'MethodStatusRule(getCode) alone, PSR-7 502 with code 0' => [
            static fn () => new RuleBasedFailureClassifier([new MethodStatusRule(['getCode'])]),
            static fn () => HttpFailure::status(502),
        ];
    }

    /**
     * @param \Closure(): FailureClassifierInterface $classifier
     * @param \Closure(): \Throwable $error
     */
    #[DataProvider('realServiceFailuresUnderOtherClassifiers')]
    public function testARealServiceFailureCountsWhateverTheClassifier(\Closure $classifier, \Closure $error): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        self::assertTrue($verdict->isServiceFailure(self::classified($error(), $classifier()), false));
    }

    /**
     * @param \Closure(): FailureClassifierInterface $classifier
     * @param \Closure(): \Throwable $error
     */
    #[DataProvider('realServiceFailuresUnderOtherClassifiers')]
    public function testAHalfOpenProbeThatGetsA5xxReopensTheCircuitWhateverTheClassifier(\Closure $classifier, \Closure $error): void
    {
        $breaker = $this->halfOpen();
        $policy = (new RtryPolicyFactory(null, $classifier()))->fromSpec('rtry:a=3;d=100;j=0;on=429');

        $result = $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict())
            ->tryOutcome(static fn (): never => throw $error(), $policy);

        self::assertSame([1, GiveUpReason::NotRetryable, []], [$result->attempts(), $result->giveUpReason(), $this->sleeper->sleeps()]);
        self::assertSame(CircuitState::Open, $breaker->state('api'));
    }

    public function testARulesStatusOverA404NeverMakesTheVerdictReadThe404sMessage(): void
    {
        // A rule replaces the 404's status, so statusFromResponse() is false; the error still
        // carries the 404, and its URL's words are not evidence of an outage.
        $classifier = RuleBasedFailureClassifier::default()->addRule(new InstanceOfRule(HttpFailure::class, 503));
        $error = HttpFailure::status(404, [], 'Not found: /search?q=connection refused');
        $outcome = self::classified($error, $classifier);

        self::assertSame(503, $outcome->statusCode());
        self::assertFalse($outcome->statusFromResponse());
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false));

        // The rule's 503 is retried; counting only the service's answer, the 404 is a success.
        $breaker = new RecordingBreaker();
        $result = $this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict(countRetryable: false))
            ->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory(null, $classifier))->fromSpec('rtry:a=3;d=100;j=0;on=5xx'));
        self::assertSame([3, GiveUpReason::AttemptsExhausted, [100, 200]], [$result->attempts(), $result->giveUpReason(), $this->sleeper->sleeps()]);
        self::assertSame(['recordSuccess', 'recordSuccess', 'recordSuccess'], $breaker->reports);
    }

    public function testFalseSaysOnlyThatTheStatusIsNotTheResponses(): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);

        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(HttpFailure::status(503), 404, [], null, false), false), 'the response, not the claim');
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(HttpFailure::status(404), 503, [], null, false), false), 'the response, not the claim');
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x', 503), 503, [], null, false), false), 'a code is no status');
        // true is trusted: the caller read it from a response the verdict may not know how to find.
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), 503, [], null, true), false));
    }

    // ---- B: a database that answered is not an outage --------------------------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function databaseAnswers(): iterable
    {
        $oracle = 'SQLSTATE[HY000]: General error: 20060 OCIStmtExecute: ORA-20060: credit limit exceeded for customer 17';
        yield 'ORA-20060 is not MySQL 2006' => [self::pdo($oracle)];
        yield 'ORA-20060 by message alone' => [new \RuntimeException($oracle)];
        $duplicate = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'broken pipe' for key 'products.name'";
        yield 'duplicate entry echoing an outage phrase' => [self::pdo($duplicate, 0, ['23000', 1062, "Duplicate entry 'broken pipe' for key 'products.name'"])];
        yield 'the same, wrapped as DBAL does' => [new \RuntimeException('An exception occurred while executing a query: ' . $duplicate, 0, self::pdo($duplicate, 0, ['23000', 1062, 'dup']))];
        yield 'the same, by message alone' => [new \RuntimeException($duplicate)];
        yield 'duplicate entry echoing a timeout' => [new \RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'timed out' for key 'products.name'")];
        yield 'duplicate entry echoing a refused connection' => [new \RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'connection refused' for key 'products.name'")];
        yield 'duplicate entry echoing an errno and a cURL number' => [new \RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ECONNRESET cURL error 7' for key 'u'")];
        yield 'data too long, by its SQLSTATE in errorInfo' => [self::pdo("Data too long for column 'name': connection reset", 0, ['22001', 1406, 'x'])];
        yield 'a syntax error echoing SQL' => [new \RuntimeException("SQLSTATE[42000]: Syntax error or access violation: 1064 near 'server has gone away' at line 1")];
        yield 'an injected SQLSTATE before the real one (DBAL 2 prints params first)' => [new \RuntimeException("An exception occurred while executing 'INSERT INTO t VALUES (?)' with params [\"SQLSTATE[08006] broken pipe\"]: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry")];
        yield 'a trigger raising its own message' => [new \RuntimeException('SQLSTATE[45000]: <<Unknown error>>: 1644 connection refused by policy')];
        yield 'PostgreSQL RAISE EXCEPTION' => [new \RuntimeException('SQLSTATE[P0001]: Raise exception: 7 ERROR:  broken pipe in shipment 12')];
        yield 'a cardinality violation' => [new \RuntimeException('SQLSTATE[21000]: Cardinality violation: 1242 Subquery returns more than 1 row near connection refused')];
        yield 'a check option violation' => [new \RuntimeException('SQLSTATE[44000]: with check option violation: broken pipe')];
        yield 'a string SQLSTATE code, as PDO sets it' => [new class ('Duplicate entry \'connection refused\' for key \'u\'') extends \PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '23000';
            }
        }];
        yield 'getSQLState(), as DBAL\'s driver exceptions have it' => [new class ('Duplicate entry \'connection refused\' for key \'u\'') extends \RuntimeException {
            public function getSQLState(): ?string
            {
                return '23000';
            }
        }];
    }

    #[DataProvider('databaseAnswers')]
    public function testADatabaseThatAnsweredIsNotAnOutage(\Throwable $error): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        self::assertFalse($verdict->isServiceFailure(self::classified($error), false));
        self::assertFalse($verdict->isServiceFailure(TestOutcome::failure(null, [], null, $error), false), 'whatever classified it');
    }

    #[DataProvider('databaseAnswers')]
    public function testADatabaseThatAnsweredGetsNoTransportTags(\Throwable $error): void
    {
        self::assertSame([], RuleBasedFailureClassifier::default()->classify($error)->getTags());
    }

    /** @return iterable<string, array{string}> */
    public static function specs(): iterable
    {
        yield 'on=5xx' => ['rtry:a=4;d=100;j=0;on=5xx'];
        yield 'on=default' => ['rtry:a=4;d=100;j=0;on=default'];
    }

    #[DataProvider('specs')]
    public function testASubmittedValueCannotOpenTheDatabasesCircuit(string $spec): void
    {
        $breaker = $this->breaker();
        $calls = 0;
        $policy = (new RtryPolicyFactory())->fromSpec($spec);
        $retry = $this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict());
        foreach (['broken pipe', 'timed out', 'connection refused'] as $value) {
            $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '$value' for key 'products.name'";
            $error = self::pdo($message, 0, ['23000', 1062, $message]);
            $result = $retry->tryOutcome(static function () use (&$calls, $error): never {
                $calls++;
                throw $error;
            }, $policy);
            self::assertSame([1, GiveUpReason::NotRetryable], [$result->attempts(), $result->giveUpReason()], $value);
        }

        self::assertSame(3, $calls, 'no call is refused');
        self::assertSame([], $this->sleeper->sleeps(), 'and none is retried');
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    public function testAnErrorWithoutAnAnsweringSqlstateIsStillReadForOutages(): void
    {
        $nothing = new class ('connection refused') extends \RuntimeException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
        $throws = new class ('connection refused') extends \RuntimeException {
            public function getSQLState(): string
            {
                throw new \LogicException('no state');
            }
        };
        $timeout = new class ('Lock wait timeout exceeded') extends \RuntimeException {
            public function getSQLState(): string
            {
                return 'HY000';
            }
        };
        self::assertTrue(UnavailableEvidence::in($nothing));
        self::assertTrue(UnavailableEvidence::in($throws));
        self::assertContains('ECONNREFUSED', RuleBasedFailureClassifier::default()->classify($nothing)->getTags());
        self::assertContains('DEADLOCK', RuleBasedFailureClassifier::default()->classify($timeout)->getTags());
    }

    public function testTheMysqlCodeInAMessageEndsAtADigitBoundary(): void
    {
        foreach ([
            'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away' => true,
            'SQLSTATE[HY000]: General error: 2006' => true,
            'SQLSTATE[HY000] [2002] No such file or directory' => true,
            'SQLSTATE[HY000] [1040] Too many connections' => true,
            'SQLSTATE[HY000]: General error: 20060 OCIStmtExecute' => false,
            'SQLSTATE[HY000]: General error: 20021 OCIStmtExecute' => false,
            'SQLSTATE[HY000] [20021] x' => false,
        ] as $message => $outage) {
            self::assertSame($outage, UnavailableEvidence::in(new \RuntimeException($message)), $message);
        }
    }

    public function testAConnectionErrorThatEchoesAValueStillCounts(): void
    {
        // The SQLSTATE says connection, so the words do not matter either way.
        self::assertTrue(UnavailableEvidence::in(self::pdo("SQLSTATE[08006] [7] could not connect: 'Duplicate entry'", 0, ['08006', 7, 'x'])));
        self::assertTrue(UnavailableEvidence::in(new \RuntimeException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly')));
    }

    // ---- C: a lock wait timeout is the database answering, like a deadlock -----------------

    /** @return iterable<string, array{\Throwable}> */
    public static function lockFailures(): iterable
    {
        yield 'MySQL 1213 deadlock' => [self::pdo('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 0, ['40001', 1213, 'Deadlock found'])];
        yield 'MySQL 1205 lock wait timeout' => [self::pdo('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', 0, ['HY000', 1205, 'Lock wait timeout exceeded'])];
        yield 'MySQL 1205 by message alone' => [new \RuntimeException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction')];
        yield 'PostgreSQL lock timeout' => [new \RuntimeException('SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout')];
    }

    #[DataProvider('lockFailures')]
    public function testALockFailureIsNotAnOutage(\Throwable $error): void
    {
        $outcome = self::classified($error);
        self::assertContains('DEADLOCK', $outcome->tags(), 'on=DEADLOCK still matches it');
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false));
    }

    #[DataProvider('lockFailures')]
    public function testALockFailureKeepsTheCircuitClosedUnderOn5xx(\Throwable $error): void
    {
        $breaker = $this->breaker();
        $retry = $this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict());
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;on=5xx');
        $calls = 0;
        for ($i = 0; $i < 3; $i++) {
            $retry->tryOutcome(static function () use (&$calls, $error): never {
                $calls++;
                throw $error;
            }, $policy);
        }

        self::assertSame(3, $calls);
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    public function testALockTimeoutStillRetriesUnderTheDefaultSpec(): void
    {
        // The verdict changes what the breaker hears; the classifier's tags and the retries stay.
        $error = new \RuntimeException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');
        $result = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;j=0;on=default'));

        self::assertSame([4, GiveUpReason::AttemptsExhausted, [100, 200, 400]], [$result->attempts(), $result->giveUpReason(), $this->sleeper->sleeps()]);
    }

    public function testATimeoutCountsUnlessItComesWithADeadlock(): void
    {
        // ETIMEDOUT is ignored only beside DEADLOCK; alone, or with a refused connection, it counts.
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['ETIMEDOUT']), false));
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['deadlock', 'ECONNREFUSED']), false));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['deadlock', 'etimedout']), false));
        self::assertTrue((new ServiceFailureVerdict(['DEADLOCK']))->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['DEADLOCK', 'ETIMEDOUT']), false), 'unless you count DEADLOCK');
    }

    // ---- E: edges that survived mutation ---------------------------------------------------

    public function testAResponseWithoutAStatusFrom100To599IsStillAResponse(): void
    {
        // The service answered, with junk: its message is not read for outage words.
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        $error = new SymfonyShapedFailure(999, [], null, 0, 'connection refused');
        $outcome = self::classified($error);
        self::assertNull($outcome->statusCode());
        self::assertTrue($outcome->statusFromResponse());
        self::assertSame([], $outcome->tags());
        self::assertFalse($verdict->isServiceFailure($outcome, false));
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('connection refused'), null, [], null, true), false));
        self::assertFalse($verdict->isServiceFailure(TestOutcome::failure(null, [], null, $error), false));
    }

    public function testTheVerdictsReaderFindsAResponseTheLocatorCannot(): void
    {
        $error = new class ('upstream said no') extends \RuntimeException {
            public int $httpStatus = 503;
        };
        $reader = new class implements ErrorResponseReaderInterface {
            public function read(\Throwable $e): ?ErrorResponse
            {
                return property_exists($e, 'httpStatus') ? new ErrorResponse($e->httpStatus) : null;
            }
        };

        self::assertFalse((new ServiceFailureVerdict([], false))->isServiceFailure(TestOutcome::failure(null, [], null, $error), false));
        self::assertTrue((new ServiceFailureVerdict([], false, $reader))->isServiceFailure(TestOutcome::failure(null, [], null, $error), false));
        self::assertTrue((new ServiceFailureVerdict([], false, $reader))->isServiceFailure(RtryAttemptOutcome::failure($error, null, [], null, false), false));
    }

    public function testADriversSqlstateCountsFromErrorInfoAndFromAStringCode(): void
    {
        self::assertTrue(UnavailableEvidence::in(self::pdo('General error', 0, ['08006', 7, 'x'])));
        self::assertTrue(UnavailableEvidence::in(self::pdo('General error', 0, ['57P01', 7, 'x'])));
        self::assertFalse(UnavailableEvidence::in(self::pdo('General error', 0, ['23000', 7, 'x'])));

        $coded = new class ('General error') extends \PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '08S01';
            }
        };
        self::assertTrue(UnavailableEvidence::in($coded));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function eachSignalAlone(): iterable
    {
        foreach ([5, 6, 7, 28, 35, 52, 55, 56, 60] as $n) {
            yield "cURL error $n" => [new \RuntimeException("cURL error $n: see the libcurl docs")];
        }
        foreach (['ECONNREFUSED', 'ETIMEDOUT', 'ECONNRESET', 'ECONNABORTED', 'EHOSTUNREACH', 'ENETUNREACH', 'EPIPE', 'ENOTFOUND', 'EAI_AGAIN'] as $name) {
            yield "errno $name" => [new \RuntimeException("write $name 10.0.0.7:443")];
        }
        foreach (['08001', '08006', '08S01', '53300', '57P01', '57P02', '57P03'] as $state) {
            yield "SQLSTATE $state by message" => [new \RuntimeException("SQLSTATE[$state] x")];
            yield "SQLSTATE $state by errorInfo" => [self::pdo('x', 0, [$state, 0, 'x'])];
        }
        foreach ([1040, 1053, 2002, 2003, 2005, 2006, 2013, 2055] as $code) {
            yield "MySQL $code by message" => [new \RuntimeException("SQLSTATE[HY000]: General error: $code x")];
            yield "MySQL $code by bracket" => [new \RuntimeException("SQLSTATE[HY000] [$code] x")];
            yield "MySQL $code by errorInfo" => [self::pdo('x', 0, ['HY000', $code, 'x'])];
            yield "MySQL $code by driver code" => [self::pdo('x', $code)];
        }
        yield 'Symfony transport type' => [new class ('x') extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface {
        }];
    }

    #[DataProvider('eachSignalAlone')]
    public function testEachSignalCountsAlone(\Throwable $error): void
    {
        self::assertTrue(UnavailableEvidence::in($error));
        self::assertTrue((new ServiceFailureVerdict([], false))->isServiceFailure(TestOutcome::failure(null, [], null, $error), false));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function nearMisses(): iterable
    {
        foreach ([3, 4, 8, 22, 47, 61, 280, 600] as $n) {
            yield "cURL error $n" => [new \RuntimeException("cURL error $n: see the libcurl docs")];
        }
        yield 'an errno name inside a word' => [new \RuntimeException('XECONNREFUSEDX')];
        yield 'SQLSTATE 08 outside a SQLSTATE' => [new \RuntimeException('order 08006 failed')];
        yield 'MySQL code in a longer number, bracketed' => [new \RuntimeException('SQLSTATE[HY000] [20021] x')];
        yield 'another MySQL code' => [new \RuntimeException('SQLSTATE[HY000]: General error: 1366 Incorrect string value')];
        yield 'a PDOException with an unrelated code' => [self::pdo('x', 1062)];
    }

    #[DataProvider('nearMisses')]
    public function testNearMissesDoNotCount(\Throwable $error): void
    {
        self::assertFalse(UnavailableEvidence::in($error));
    }
}
