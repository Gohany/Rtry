<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\Breaker\UnavailableEvidence;
use Gohany\Rtry\Impl\DatabaseAnswer;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\DbalShaped;
use Gohany\Rtry\Tests\Support\DbErrors;
use Gohany\Rtry\Tests\Support\PdoShaped;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The hostile re-verification of PR #9 (head 27e83a2, 2026-11-19), fixed before 2.4.1 is tagged.
 *
 * Blocker 1: PDO's prefix was read from the message of a LogicException or an \Error, so a
 *   validation error starting with the submitted value opened the circuit and was retried.
 * Blocker 2: ProxySQL's own query timeout (1907) and the numbers Vitess and relaying engines
 *   send with free text (1317, 1815, 1203, 1041, 1296, 1297) were taken for the MySQL server's
 *   answer. They read exactly as in 2.4.0 again.
 * Should-fix 1 to 5 and the nits: an unrelated error above an answer is read again, MySQL's
 *   idle disconnect (4031) counts, tags keep 2.4.0's order, and the unpinned edges are pinned.
 *
 * The ProxySQL rows are its own texts, from lib/MySQL_Session.cpp and lib/MySQL_ResultSet.cpp in
 * ProxySQL 2.7.1; the Vitess numbers from go/mysql/sqlerror/sql_error.go.
 */
final class TagReverifyFindingsTest extends TestCase
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

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(new InMemoryCircuitBreakerStore($this->clock), $this->clock, failureThreshold: 2, windowMs: 10_000, openMs: 5_000);
    }

    private static function counts(\Throwable $e): bool
    {
        $outcome = RtryAttemptOutcome::fromMetadata($e, RuleBasedFailureClassifier::default()->classify($e), self::T0);
        return (new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false);
    }

    /** @return list<string> sorted */
    private static function tags(\Throwable $e): array
    {
        $tags = RuleBasedFailureClassifier::default()->classify($e)->getTags();
        sort($tags);
        return $tags;
    }

    /** Runs $calls calls of an operation that always throws $error; returns how often it ran. */
    private function calls(Retry $retry, \Throwable $error, string $spec, int $calls = 3): int
    {
        $ran = 0;
        $policy = (new RtryPolicyFactory())->fromSpec($spec);
        for ($i = 0; $i < $calls; $i++) {
            try {
                $retry->try(static function () use (&$ran, $error): never {
                    $ran++;
                    throw $error;
                }, $policy);
            } catch (\Throwable) {
                // the error, or CircuitOpenException
            }
        }
        return $ran;
    }

    private static function duplicate(string $value = 'a'): \PDOException
    {
        return DbErrors::pdo('23000', 1062, "Duplicate entry '$value' for key 't.name'");
    }

    // ---- Blocker 1: a validation error's message is never read ------------------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function validationErrors(): iterable
    {
        foreach (['SQLSTATE[08006]', 'SQLSTATE[HY000] [2002]', 'SQLSTATE[HY000]: General error: 2006', 'SQLSTATE[53300]'] as $code) {
            $message = "$code is not a valid coupon code";
            yield "InvalidArgumentException $code" => [new \InvalidArgumentException($message)];
            yield "DomainException $code" => [new \DomainException($message)];
            yield "ValueError $code" => [new \ValueError($message)];
            yield "TypeError $code" => [new \TypeError($message)];
        }
    }

    #[DataProvider('validationErrors')]
    public function testAValidationErrorStartingWithAConnectionSqlstateIsNotAnOutage(\Throwable $error): void
    {
        self::assertFalse(DatabaseAnswer::isConnectionError($error));
        self::assertFalse(UnavailableEvidence::in($error));
        self::assertFalse(self::counts($error));

        $breaker = $this->breaker();
        $ran = $this->calls($this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=4;d=100;j=0;on=5xx');
        self::assertSame(3, $ran, 'each call ran once, none was refused');
        self::assertSame([], $this->sleeper->sleeps());
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    public function testAValidationErrorStartingWithADeadlockSqlstateIsNotRetried(): void
    {
        $error = new \InvalidArgumentException('SQLSTATE[40001] is not a valid coupon code');
        self::assertSame([], self::tags($error));
        self::assertFalse(DatabaseAnswer::is($error));

        $breaker = $this->breaker();
        $ran = $this->calls($this->retry()->withGuard('db', null, $breaker), $error, 'rtry:a=4;d=100;j=0;on=default');
        self::assertSame(3, $ran, 'one attempt per call');
        self::assertSame([], $this->sleeper->sleeps());
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    public function testAValidationErrorOverARealOutageStillCounts(): void
    {
        self::assertTrue(self::counts(new \LogicException('SQLSTATE[23000] is not a valid coupon code', 0, DbErrors::pdoConnect('HY000', 2002, 'Connection refused'))));
    }

    public function testALogicExceptionsOwnFieldsAreStillRead(): void
    {
        self::assertTrue(DatabaseAnswer::isConnectionError(new LogicWithSqlState('x', '08006')));
        self::assertTrue(self::counts(new LogicWithSqlState('x', '08006')));
    }

    // ---- Blocker 2: proxy and relay numbers read exactly as in 2.4.0 ------------------------

    /** @return iterable<string, array{\Throwable}> ProxySQL's query_timeout, real text */
    public static function proxySqlQueryTimeouts(): iterable
    {
        $text = 'Query execution was interrupted, query_timeout exceeded';
        yield 'pdo' => [PdoShaped::make("SQLSTATE[70100]: <<Unknown error>>: 1907 $text", '70100', ['70100', 1907, $text])];
        yield 'by message alone' => [new \RuntimeException("SQLSTATE[70100]: <<Unknown error>>: 1907 $text")];
        yield 'Laravel' => [DbErrors::laravel(PdoShaped::make("SQLSTATE[70100]: <<Unknown error>>: 1907 $text", '70100', ['70100', 1907, $text]), 'select sleep(3)', [])];
        yield 'DBAL' => [DbErrors::dbal(PdoShaped::make("SQLSTATE[70100]: <<Unknown error>>: 1907 $text", '70100', ['70100', 1907, $text]))];
    }

    #[DataProvider('proxySqlQueryTimeouts')]
    public function testProxySqlsQueryTimeoutRetriesAndOpensTheCircuitAsIn240(\Throwable $error): void
    {
        self::assertFalse(DatabaseAnswer::is($error));
        self::assertSame(['ETIMEDOUT'], self::tags($error));

        $result = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;j=0;on=default'));
        self::assertSame([4, GiveUpReason::AttemptsExhausted, [100, 200, 400]], [$result->attempts(), $result->giveUpReason(), $this->sleeper->sleeps()]);

        $breaker = $this->breaker();
        self::assertSame(2, $this->calls($this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=3;d=100;j=0;on=5xx'), 'the third call is refused');
        self::assertSame(CircuitState::Open, $breaker->state('db'));
    }

    #[RequiresPhpExtension('mysqli')]
    public function testProxySqlsQueryTimeoutThroughMysqli(): void
    {
        $error = DbErrors::mysqli('70100', 1907, 'Query execution was interrupted, query_timeout exceeded');
        self::assertSame(['ETIMEDOUT'], self::tags($error));
        self::assertTrue(self::counts($error));
    }

    public function testAnother1907IsStillAnAnswer(): void
    {
        // MariaDB's 1907 is about a generated column and echoes its names.
        $error = DbErrors::pdo('HY000', 1907, "Expression for field 'timed out' is referring to uninitialized field 'connection refused'");
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertSame([], self::tags($error));
        self::assertFalse(self::counts($error));
    }

    /**
     * What 2.4.0 gave each, measured on 2.4.0: sorted tags, whether the verdict counts it, attempts under rtry:a=3;on=default.
     *
     * @return iterable<string, array{\Throwable, list<string>, bool, int}>
     */
    public static function proxyAndRelayErrors(): iterable
    {
        // ProxySQL 2.7.1's own errors in MySQL's server range.
        yield 'ProxySQL 1317 KILL' => [DbErrors::pdo('70100', 1317, 'Query execution was interrupted'), [], false, 1];
        yield 'ProxySQL 1243' => [DbErrors::pdo('HY000', 1243, 'Unknown prepared statement handler (3) given to mysql_stmt_precheck'), [], false, 1];
        yield 'ProxySQL 1226' => [DbErrors::pdo('42000', 1226, "User 'app' has exceeded the 'max_user_connections' resource (current value: 10)"), [], false, 1];
        yield 'ProxySQL 1040' => [DbErrors::pdo('08004', 1040, 'Too many connections'), [], true, 1];
        yield 'ProxySQL 1153' => [DbErrors::pdo('08S01', 1153, "Got a packet bigger than 'max_allowed_packet' bytes"), [], true, 1];
        yield 'ProxySQL 1148' => [DbErrors::pdo('42000', 1148, 'Backend not connected'), [], false, 1];
        yield 'ProxySQL 1045' => [DbErrors::pdo('28000', 1045, 'Command not supported'), [], false, 1];
        yield 'ProxySQL 1047' => [DbErrors::pdo('HY000', 1047, "Unsupported 'LOAD DATA LOCAL INFILE' command"), [], false, 1];
        yield 'ProxySQL 1064' => [DbErrors::pdo('42000', 1064, 'Unknown PROXYSQL INTERNAL command'), [], false, 1];
        yield 'ProxySQL 9001' => [DbErrors::pdo('HY000', 9001, 'Max connect timeout reached while reaching hostgroup 0 after 2000ms'), ['ETIMEDOUT'], true, 3];
        yield 'ProxySQL 9002' => [DbErrors::pdo('HY000', 9002, 'Max connect failure while reaching hostgroup 0'), [], false, 1];
        yield 'ProxySQL 9005' => [DbErrors::pdo('HY000', 9005, 'Session trying to reach HG 1 while locked on HG 0 . Rejecting query: select 1'), [], false, 1];
        yield 'ProxySQL 9006' => [DbErrors::pdo('Y0000', 9006, 'ProxySQL Error: connection is locked to hostgroup 0 but trying to reach hostgroup 1'), [], false, 1];
        // Vitess: CANCELED, DEADLINE_EXCEEDED and ABORTED as 1317, INTERNAL as 1815,
        // RESOURCE_EXHAUSTED as 1203 or 1041, each with vttablet's own text.
        yield 'Vitess 1317 deadline' => [DbErrors::pdo('70100', 1317, 'vttablet: rpc error: code = DeadlineExceeded desc = context deadline exceeded'), [], false, 1];
        yield 'Vitess 1317 timed out' => [DbErrors::pdo('70100', 1317, 'target: ks.-.primary: vttablet: rpc error: code = Canceled desc = connection timed out'), ['ETIMEDOUT'], true, 3];
        yield 'Vitess 1815 refused' => [DbErrors::pdo('HY000', 1815, 'Internal error: vttablet: rpc error: code = Unavailable desc = connection refused'), ['ECONNREFUSED'], true, 3];
        yield 'Vitess 1203 pool' => [DbErrors::pdo('42000', 1203, 'vttablet: rpc error: code = ResourceExhausted desc = resource pool timed out'), ['ETIMEDOUT'], true, 3];
        yield 'Vitess 1041 throttled' => [DbErrors::pdo('HY000', 1041, 'vttablet: rpc error: code = ResourceExhausted desc = Transaction throttled: connection timed out'), ['ETIMEDOUT'], true, 3];
        // Engines relaying another server's error.
        yield 'FEDERATED 1296' => [DbErrors::pdo('HY000', 1296, "Got error 10000 'Error on remote system: 2013: Lost connection to MySQL server during query' from FEDERATED"), [], true, 1];
        yield 'NDB 1297' => [DbErrors::pdo('HY000', 1297, "Got temporary error 4010 'Node failure caused abort of transaction: connection timed out' from NDBCLUSTER"), ['ETIMEDOUT'], true, 3];
        yield 'FEDERATED 1430' => [DbErrors::pdo('HY000', 1430, 'There was a problem processing the query on the foreign data source. Data source error: Lost connection to MySQL server during query'), [], true, 1];
    }

    /** @param list<string> $tags */
    #[DataProvider('proxyAndRelayErrors')]
    public function testAProxyOrRelayErrorReadsAsIn240(\Throwable $error, array $tags, bool $counts, int $attempts): void
    {
        self::assertSame($tags, self::tags($error), 'tags');
        self::assertSame($counts, self::counts($error), 'verdict');
        $result = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;on=default'));
        self::assertSame($attempts, $result->attempts(), 'attempts');
    }

    // ---- Should-fix 1: an unrelated error above an answer is read --------------------------

    public function testAnErrorThrownInAFinallyOverAnAnswerCounts(): void
    {
        $error = null;
        try {
            try {
                throw self::duplicate();
            } finally {
                // PHP makes the duplicate this error's previous.
                throw new \RuntimeException('read error on connection to cache:6379');
            }
        } catch (\RuntimeException $e) {
            $error = $e;
        }
        self::assertInstanceOf(\PDOException::class, $error->getPrevious());
        self::assertTrue(self::counts($error));

        $breaker = $this->breaker();
        self::assertSame(2, $this->calls($this->retry()->withGuard('cache', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=3;d=100;j=0;on=5xx'));
        self::assertSame(CircuitState::Open, $breaker->state('cache'));
        // As the half-open probe, it reopens the circuit.
        $this->clock->advanceMs(5_000);
        $this->calls($this->retry()->withGuard('cache', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=3;d=100;j=0;on=5xx', 1);
        self::assertSame(CircuitState::Open, $breaker->state('cache'));
    }

    public function testATimeoutOverAnAnswerIsRetried(): void
    {
        $error = new \RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', 0, self::duplicate());
        self::assertSame(['ETIMEDOUT'], self::tags($error));
        $result = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;on=default'));
        self::assertSame([3, [100, 200]], [$result->attempts(), $this->sleeper->sleeps()]);
    }

    public function testARefusedConnectionOverAnAnswerCounts(): void
    {
        self::assertTrue(self::counts(new \ErrorException('fsockopen(): Unable to connect to smtp:25 (Connection refused)', 0, E_WARNING, 'Mailer.php', 12, self::duplicate())));
        // A message-only answer below: "SQLSTATE[23000]" is only at the start of the inner message.
        self::assertTrue(self::counts(new \RuntimeException('cURL error 7: Failed to connect to api port 443: Connection refused', 0, new \RuntimeException('SQLSTATE[23000]: lookup failed'))));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function wrappersQuotingTheAnswer(): iterable
    {
        $pdo = self::duplicate('broken pipe');
        yield 'its message' => [new \RuntimeException('Saving failed: ' . $pdo->getMessage(), 0, $pdo)];
        yield "the driver's text" => [new \RuntimeException("Could not save: Duplicate entry 'broken pipe' for key 't.name'", 0, $pdo)];
        yield 'two wrappers' => [new \RuntimeException('Order 7: Saving failed: ' . $pdo->getMessage(), 0, new \RuntimeException('Saving failed: ' . $pdo->getMessage(), 0, $pdo))];
        yield 'another case' => [new \RuntimeException('SAVING FAILED: ' . strtoupper($pdo->getMessage()), 0, $pdo)];
    }

    #[DataProvider('wrappersQuotingTheAnswer')]
    public function testAWrapperQuotingTheAnswerIsStillNotRead(\Throwable $error): void
    {
        self::assertFalse(self::counts($error));
        self::assertSame([], self::tags($error));
    }

    public function testAWrapperQuotingTheAnswerWithoutItsTrailingNewlineIsNotRead(): void
    {
        // pdo_pgsql's text can end in a newline; a wrapper that trims it still quotes the answer.
        $text = "ERROR:  duplicate key value violates unique constraint \"t_name_key\"\nDETAIL:  Key (name)=(broken pipe) already exists.\n";
        $pdo = PdoShaped::make("SQLSTATE[23505]: Unique violation: 7 $text", '23505', ['23505', 7, $text]);
        self::assertFalse(self::counts(new \RuntimeException('Saving failed: ' . rtrim($pdo->getMessage()), 0, $pdo)));
    }

    public function testAWrapperQuotingTheAnswerInAnotherErrorAboveItIsNotRead(): void
    {
        // The middle error is not read: it quotes the answer. The outer one only quotes the middle one's text.
        $pdo = self::duplicate('timed out');
        $error = new \RuntimeException('Import row 3: ' . 'Saving failed: ' . $pdo->getMessage(), 0, new \RuntimeException('Saving failed: ' . $pdo->getMessage(), 0, $pdo));
        self::assertSame([], self::tags($error));
    }

    public function testAWrapperQuotingAMessageOnlyAnswerIsNotRead(): void
    {
        // PDO's text after the prefix is empty here, so only the answer's message is quoted.
        $error = new \RuntimeException('Saving failed: SQLSTATE[23000]: broken pipe', 0, new \RuntimeException('SQLSTATE[23000]: broken pipe'));
        self::assertFalse(self::counts($error));
    }

    public function testAValidationErrorsWordsDoNotMakeALockTimeout(): void
    {
        // A real timeout, over a validation error naming a lock timeout, over SQL Server's 1205.
        $dblib = DbErrors::pdo('HY000', 1205, 'Transaction (Process ID 52) was deadlocked on lock resources with another process and has been chosen as the deadlock victim. Rerun the transaction.');
        // "Read timed out" gives ETIMEDOUT but is not UnavailableEvidence on its own (cURL 28 would be).
        $error = new \RuntimeException('Read timed out after 30 s', 0, new \LogicException('Lock wait timeout exceeded is not a valid status', 0, $dblib));
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($error));
        self::assertTrue(self::counts($error));
    }

    // ---- Should-fix 2: request text at the start of a message (documented, not covered) ------

    public function testRequestTextAtTheStartOfAMessageIsReadAsPdosPrefix(): void
    {
        // README, "Not covered": the start of a message is taken to be written by the code that threw.
        self::assertFalse(self::counts(new \RuntimeException('SQLSTATE[23000]: could not connect to tenant database: Connection refused')));
    }

    // ---- Should-fix 3: MySQL's idle disconnect ----------------------------------------------

    public function testMysqlsIdleDisconnectCounts(): void
    {
        $text = 'The client was disconnected by the server because of inactivity. See wait_timeout and interactive_timeout for configuring this behavior.';
        $pdo = DbErrors::pdo('HY000', 4031, $text);
        self::assertTrue(DatabaseAnswer::isConnectionError($pdo));
        self::assertTrue(self::counts($pdo));
        self::assertTrue(self::counts(new \RuntimeException($pdo->getMessage())), 'by message alone');
        self::assertTrue(self::counts(DbErrors::laravel($pdo, 'select ?', ['duplicate'])));
        // Retries are unchanged: 2.4.0 gave it no tags.
        self::assertSame([], self::tags($pdo));
    }

    #[RequiresPhpExtension('mysqli')]
    public function testMysqlsIdleDisconnectThroughMysqliCounts(): void
    {
        self::assertTrue(self::counts(DbErrors::mysqli('HY000', 4031, 'The client was disconnected by the server because of inactivity. See wait_timeout and interactive_timeout for configuring this behavior.')));
    }

    // ---- Should-fix 5: tags in 2.4.0's order ------------------------------------------------

    public function testLockTimeoutTagsKeep240sOrder(): void
    {
        $text = 'Lock wait timeout exceeded; try restarting transaction';
        $order = static fn (\Throwable $e): array => RuleBasedFailureClassifier::default()->classify($e)->getTags();
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], $order(DbErrors::pdo('HY000', 1205, $text)), 'PDO: its errorInfo number first, as in 2.4.0');
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], $order(new \RuntimeException("SQLSTATE[HY000]: General error: 1205 $text")), 'by message alone');
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], $order(DbErrors::pdo('HY000', 3133, 'Service lock wait timeout exceeded.')));
    }

    public function testAPdoExceptionsDeadlockSqlstateComesFirstAsIn240(): void
    {
        $error = PdoShaped::make('SQLSTATE[40001]: Serialization failure: 3133 Service lock wait timeout exceeded.', '40001', ['40001', 3133, 'Service lock wait timeout exceeded.']);
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], RuleBasedFailureClassifier::default()->classify($error)->getTags());
    }

    #[RequiresPhpExtension('mysqli')]
    public function testMysqliLockTimeoutTagsKeep240sOrder(): void
    {
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], RuleBasedFailureClassifier::default()->classify(DbErrors::mysqli('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'))->getTags());
    }

    // ---- Nits ---------------------------------------------------------------------------

    public function testTheMessagesTextIsReadBesideErrorInfos(): void
    {
        // Nit 1: a hand-built or re-thrown PDOException whose errorInfo[2] is not the server's text.
        $pg = PdoShaped::make('SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout', '55P03', ['55P03', 7, 'x']);
        self::assertFalse(self::counts($pg));
        $my = PdoShaped::make('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', 'HY000', ['HY000', 1205, 'x']);
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($my));
        self::assertFalse(self::counts($my));
        // And errorInfo's text is read when the message is rewritten.
        $rewritten = PdoShaped::make('Statement failed', 'HY000', ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction']);
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($rewritten));
        // Leading whitespace before the driver's text is not part of its start.
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags(PdoShaped::make('Statement failed', 'HY000', ['HY000', 1205, '  Lock wait timeout exceeded; try restarting transaction'])));
    }

    public function testDbalsLockWaitTimeoutTypeIsALockTimeout(): void
    {
        // Nit 2: DBAL over mysqli's StatementError, with no mysqli_sql_exception below.
        require_once __DIR__ . '/Support/third-party-types.php';
        $statement = new DbalShaped('Lock wait timeout exceeded; try restarting transaction', 1205, 'HY000');
        $error = (new \ReflectionClass('Doctrine\\DBAL\\Exception\\LockWaitTimeoutException'))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Exception::class, 'message'))->setValue($error, 'An exception occurred while executing a query: Lock wait timeout exceeded; try restarting transaction');
        (new \ReflectionProperty(\Exception::class, 'previous'))->setValue($error, $statement);
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($error));
        self::assertFalse(self::counts($error));
    }

    public function testALockTimeoutInsideAMessageIsReadAsIn240(): void
    {
        // README, "Not covered": only the start of a message is taken as the database's.
        self::assertTrue(self::counts(new \RuntimeException('Query failed: SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction')));
    }

    /** @return iterable<string, array{string}> */
    public static function oracleConnectErrors(): iterable
    {
        yield 'ORA-01033' => ['ORA-01033: ORACLE initialization or shutdown in progress'];
        yield 'ORA-01034' => ['ORA-01034: ORACLE not available'];
        yield 'ORA-03113' => ['ORA-03113: end-of-file on communication channel'];
        yield 'ORA-03135' => ['ORA-03135: connection lost contact'];
    }

    #[DataProvider('oracleConnectErrors')]
    public function testPdoOcisConnectErrorIsNotAMysqlNumber(string $text): void
    {
        // Nit 3: PDO_OCI's connect error has the ORA number as its code and no errorInfo.
        $error = PdoShaped::make("SQLSTATE[01002]: OCISessionBegin: $text (ext/pdo_oci/oci_driver.c:179)", (int) substr($text, 4, 5), null);
        self::assertFalse(DatabaseAnswer::is($error));
    }

    public function testAnOraNumberInAValueDoesNotMakeAnOracleError(): void
    {
        $error = DbErrors::pdo('HY000', 1366, "Incorrect integer value: 'OCI ORA-03113 broken pipe' for column 'qty' at row 1");
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
    }

    public function testAnOracleNumberIsNotAMysqlDeadlock(): void
    {
        $error = PdoShaped::make('SQLSTATE[HY000]: General error: 1213 OCIStmtExecute: ORA-01213: MAXINSTANCES may not exceed 255', 'HY000', ['HY000', 1213, 'OCIStmtExecute: ORA-01213: MAXINSTANCES may not exceed 255']);
        self::assertSame([], self::tags($error));
    }

    public function testPostgresNoConnectionCounts(): void
    {
        // Nit 4: the query after PostgreSQL ended the backend.
        self::assertTrue(self::counts(PdoShaped::make('SQLSTATE[HY000]: General error: 7 no connection to the server', 'HY000', ['HY000', 7, "no connection to the server\n"])));
    }

    // ---- Nit 6: the edges that survived mutation --------------------------------------------

    public function testA1213WithOtherTextIsStillADeadlockByNumber(): void
    {
        self::assertSame(['DEADLOCK'], self::tags(DbErrors::pdo('HY000', 1213, 'x')));
        self::assertSame(['DEADLOCK'], self::tags(new \RuntimeException('SQLSTATE[HY000]: General error: 1213 x')));
    }

    public function testPdosPrefixIsOnlyAtTheVeryStart(): void
    {
        self::assertTrue(self::counts(new \RuntimeException(" SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'broken pipe'")));
    }

    public function testLockTimeoutWordsCountOnlyAtTheStart(): void
    {
        self::assertTrue(self::counts(new \RuntimeException("Read timed out for sku 'lock wait timeout exceeded'")));
        self::assertTrue(self::counts(new \RuntimeException("Read timed out for sku 'canceling statement due to lock timeout'")));
    }

    public function testALockTimeoutUnderAWrapperIsNotAnOutage(): void
    {
        self::assertFalse(self::counts(DbErrors::dbal(DbErrors::pdo('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'))));
        self::assertFalse(self::counts(new \RuntimeException('Saving failed', 0, DbErrors::pdo('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'))));
    }

    public function testA1205ThatIsNotALockTimeoutLeavesATimeoutCounting(): void
    {
        // SQL Server's 1205 (deadlock victim) through pdo_dblib, under a real timeout.
        $dblib = DbErrors::pdo('HY000', 1205, 'Transaction (Process ID 52) was deadlocked on lock resources with another process and has been chosen as the deadlock victim. Rerun the transaction.');
        $error = new \RuntimeException('Read timed out after 30 s', 0, $dblib);
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($error));
        self::assertTrue(self::counts($error));
    }

    public function testErrorInfosFieldsComeFirst(): void
    {
        // A numeric-string errorInfo[1].
        self::assertFalse(self::counts(PdoShaped::make("Statement failed: Incorrect integer value: 'broken pipe'", 'HY000', ['HY000', '1366', "Incorrect integer value: 'broken pipe' for column 'qty'"])));
        // errorInfo's number over the int code.
        self::assertFalse(self::counts(PdoShaped::make("SQLSTATE[HY000] [1049] Unknown database 'tenant_broken pipe'", 2006, ['HY000', 1049, "Unknown database 'tenant_broken pipe'"])));
        // errorInfo's number over the message's.
        self::assertFalse(self::counts(PdoShaped::make("SQLSTATE[HY000]: General error: 2006 Incorrect integer value: 'broken pipe'", 'HY000', ['HY000', 1366, "Incorrect integer value: 'broken pipe'"])));
    }

    public function testAConnectionSqlstateInsideAMessageCounts(): void
    {
        self::assertTrue(self::counts(new \RuntimeException('Query failed: SQLSTATE[08006] [7] FATAL:  the end')));
    }
}

/** @internal a LogicException that carries a SQLSTATE field, as a library might. */
final class LogicWithSqlState extends \LogicException
{
    public function __construct(string $message, private string $sqlState)
    {
        parent::__construct($message);
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }
}
