<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitState;
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
use Gohany\Rtry\Tests\Support\DbErrors;
use Gohany\Rtry\Tests\Support\PdoShaped;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The re-verification of PR #9 at df3d975 (2.4.1, 2026-11-19), fixed for 2.5.0.
 *
 * Should-fix 1: 4031 counted by number alone. On MariaDB it is a DDL answer ("Referenced
 *   trigger ... does not exist"), not MySQL's idle disconnect, and it opened the circuit.
 * Should-fix 2: a MySQL-compatible product's own outage under a MySQL server number
 *   (MaxScale's 1927, SingleStore's 1735) was read as the server's answer.
 * Nit 1: a plain wrapper quoting a 1205 PDOException gave DEADLOCK before ETIMEDOUT.
 * Nit 3: any driver text counted as a quote, however short.
 * Nit 4: ORA-04031 through PDO_OCI counted as MySQL's 4031 (closed by should-fix 1).
 * Nit 5: the three edges that survived mutation are pinned.
 */
final class PatchReverifyFindingsTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private const MYSQL_IDLE = 'The client was disconnected by the server because of inactivity. See wait_timeout and interactive_timeout for configuring this behavior.';
    private const MARIADB_TRIGGER = "Referenced trigger 'tenant_acme_audit' for the given action time and event type does not exist";

    private FrozenClock $clock;
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(self::T0);
        $this->sleeper = new RecordingSleeper($this->clock);
    }

    private function retry(): Retry
    {
        return new Retry($this->clock, $this->sleeper, null, SeededRandom::randomizer(242));
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

    /** @return list<string> in the classifier's order */
    private static function tags(\Throwable $e): array
    {
        return RuleBasedFailureClassifier::default()->classify($e)->getTags();
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

    // ---- Should-fix 1: MariaDB's 4031 is a DDL answer -----------------------------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function mariaDbTriggerErrors(): iterable
    {
        $pdo = DbErrors::pdo('HY000', 4031, self::MARIADB_TRIGGER);
        yield 'PDO' => [$pdo];
        yield 'by message alone' => [new \RuntimeException($pdo->getMessage())];
        yield 'a wrapper quoting it' => [new \RuntimeException('Provisioning failed: ' . $pdo->getMessage(), 0, $pdo)];
        yield 'its message inside another, no previous' => [new \RuntimeException('Provisioning failed: ' . $pdo->getMessage())];
        yield 'Laravel' => [DbErrors::laravel($pdo, 'create trigger audit_orders before insert on orders for each row follows ?', ['tenant_acme_audit'])];
        yield 'DBAL' => [DbErrors::dbal($pdo)];
    }

    #[DataProvider('mariaDbTriggerErrors')]
    public function testMariaDbs4031IsNotAnOutage(\Throwable $error): void
    {
        self::assertFalse(UnavailableEvidence::in($error));
        self::assertFalse(self::counts($error));

        $breaker = $this->breaker();
        $ran = $this->calls($this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=4;d=100;j=0;on=5xx');
        self::assertSame(3, $ran, 'each call ran once, none was refused');
        self::assertSame([], $this->sleeper->sleeps());
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    #[RequiresPhpExtension('mysqli')]
    public function testMariaDbs4031ThroughMysqliIsNotAnOutage(): void
    {
        $error = DbErrors::mysqli('HY000', 4031, self::MARIADB_TRIGGER);
        self::assertFalse(DatabaseAnswer::isConnectionError($error));
        self::assertFalse(self::counts($error));
    }

    public function testMariaDbs4031IsTheDatabasesAnswer(): void
    {
        $pdo = DbErrors::pdo('HY000', 4031, self::MARIADB_TRIGGER);
        self::assertFalse(DatabaseAnswer::isConnectionError($pdo));
        self::assertTrue(DatabaseAnswer::is($pdo));
        self::assertSame([], self::tags($pdo));
    }

    public function testMariaDbs4031AsAHalfOpenProbeClosesTheCircuit(): void
    {
        $breaker = $this->breaker();
        $retry = $this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict());
        $this->calls($retry, DbErrors::pdo('HY000', 2006, 'MySQL server has gone away'), 'rtry:a=1;d=100;on=5xx', 2);
        self::assertSame(CircuitState::Open, $breaker->state('db'));

        $this->clock->advanceMs(5_000);
        self::assertSame(1, $this->calls($retry, DbErrors::pdo('HY000', 4031, self::MARIADB_TRIGGER), 'rtry:a=1;d=100;on=5xx', 1));
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function mysqlIdleDisconnects(): iterable
    {
        $pdo = DbErrors::pdo('HY000', 4031, self::MYSQL_IDLE);
        yield 'PDO' => [$pdo];
        yield 'by message alone' => [new \RuntimeException($pdo->getMessage())];
        yield 'inside another message, no previous' => [new \RuntimeException('Job 7 failed: ' . $pdo->getMessage())];
        yield 'Laravel' => [DbErrors::laravel($pdo, 'select ?', ['duplicate'])];
        yield 'DBAL' => [DbErrors::dbal($pdo)];
        yield 'errorInfo text only' => [PdoShaped::make('Statement failed', 'HY000', ['HY000', 4031, self::MYSQL_IDLE])];
        yield 'leading whitespace' => [PdoShaped::make('Statement failed', 'HY000', ['HY000', 4031, '  ' . self::MYSQL_IDLE])];
        yield 'another case' => [new \RuntimeException(strtoupper($pdo->getMessage()))];
    }

    #[DataProvider('mysqlIdleDisconnects')]
    public function testMysqlsIdleDisconnectStillCounts(\Throwable $error): void
    {
        self::assertTrue(UnavailableEvidence::in($error));
        self::assertTrue(self::counts($error));
        self::assertSame([], self::tags($error), 'retries unchanged: 2.4.0 gave it no tags');
    }

    public function testA4031WithNoTextIsNotAnOutage(): void
    {
        // As in 2.4.0, which had no 4031: a number alone, without MySQL's text, does not say which server.
        self::assertFalse(self::counts(PdoShaped::make('Statement failed', 4031, ['HY000', 4031, ''])));
        self::assertFalse(self::counts(new \RuntimeException('SQLSTATE[HY000]: General error: 4031')));
    }

    public function testOracles04031IsNotMysqls4031(): void
    {
        // Nit 4: ORA-04031 (shared pool exhausted) through PDO_OCI, S S S as in 2.4.0.
        $text = 'OCIStmtExecute: ORA-04031: unable to allocate 4096 bytes of shared memory ("shared pool","unknown object","sga heap(1,0)","kglsim object batch") (ext/pdo_oci/oci_statement.c:159)';
        $error = PdoShaped::make("SQLSTATE[HY000]: General error: 4031 $text", 'HY000', ['HY000', 4031, $text]);
        self::assertFalse(self::counts($error));
        self::assertFalse(self::counts(new \RuntimeException($error->getMessage())));
    }

    public function testOraclesConnectionNumbersStillCountAsIn240(): void
    {
        // ORA-02006 and ORA-01040 counted on 2.4.0 by their MySQL number; unchanged.
        foreach ([2006 => 'ORA-02006: invalid packed decimal format string', 1040 => 'ORA-01040: invalid character in password'] as $n => $ora) {
            $text = "OCIStmtExecute: $ora";
            self::assertTrue(self::counts(PdoShaped::make("SQLSTATE[HY000]: General error: $n $text", 'HY000', ['HY000', $n, $text])), (string) $n);
        }
    }

    // ---- Should-fix 2: MySQL-compatible products' own outages ---------------------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function compatibleProductOutages(): iterable
    {
        $maxscale = 'Lost connection to backend server: network error (node2, Connection refused)';
        yield 'MaxScale 1927, PDO' => [DbErrors::pdo('HY000', 1927, $maxscale)];
        yield 'MaxScale 1927, by message alone' => [new \RuntimeException("SQLSTATE[HY000]: General error: 1927 $maxscale")];
        $singlestore = "Unable to connect to leaf @10.0.0.5:3306 with user distributed, using password YES: [2004] Cannot connect to '10.0.0.5':3306. Errno=111 (Connection refused)";
        yield 'SingleStore 1735, PDO' => [DbErrors::pdo('HY000', 1735, $singlestore)];
        yield 'SingleStore 1735, by message alone' => [new \RuntimeException("SQLSTATE[HY000]: General error: 1735 $singlestore")];
    }

    #[DataProvider('compatibleProductOutages')]
    public function testAMysqlCompatibleProductsOwnOutageCountsAsIn240(\Throwable $error): void
    {
        self::assertFalse(DatabaseAnswer::is($error));
        self::assertContains('ECONNREFUSED', self::tags($error));
        self::assertTrue(self::counts($error));

        $breaker = $this->breaker();
        $ran = $this->calls($this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=4;d=100;j=0;on=default');
        self::assertSame(2, $ran, 'retried, then the circuit opened and refused the rest');
        self::assertSame(CircuitState::Open, $breaker->state('db'));
    }

    #[RequiresPhpExtension('mysqli')]
    public function testMaxScales1927ThroughMysqliCounts(): void
    {
        self::assertTrue(self::counts(DbErrors::mysqli('HY000', 1927, 'Lost connection to backend server: network error (node2, Connection refused)')));
    }

    public function testMysqlsOwn1735IsStillAnAnswer(): void
    {
        // MySQL's and MariaDB's 1735 is "Unknown partition '%s' in table '%s'": it echoes a name.
        $error = DbErrors::pdo('HY000', 1735, "Unknown partition 'connection refused' in table 't'");
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
        self::assertSame([], self::tags($error));
    }

    public function testMariaDbsOwn1927IsReadAsIn240(): void
    {
        // MariaDB's 1927 is ER_CONNECTION_KILLED, "Connection was killed": no outage words, as in 2.4.0.
        $error = DbErrors::pdo('70100', 1927, 'Connection was killed');
        self::assertFalse(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
    }

    // ---- Nit 1: a plain wrapper quoting a lock wait timeout keeps 2.4.0's order ----------------

    public function testAWrapperQuotingALockWaitTimeoutKeeps240sOrder(): void
    {
        $text = 'Lock wait timeout exceeded; try restarting transaction';
        $pdo = DbErrors::pdo('HY000', 1205, $text);
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], self::tags(new \RuntimeException('Saving failed: ' . $pdo->getMessage(), 0, $pdo)));
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], self::tags(new \RuntimeException("Saving failed: $text", 0, $pdo)), "quoting the driver's text");
        $service = DbErrors::pdo('HY000', 3133, 'Service lock wait timeout exceeded.');
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], self::tags(new \RuntimeException('Saving failed: ' . $service->getMessage(), 0, $service)));
        // Unchanged: the PDOException itself, by message alone, and DBAL.
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($pdo));
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], self::tags(new \RuntimeException($pdo->getMessage())));
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], self::tags(DbErrors::dbal($pdo)));
        // A wrapper quoting a duplicate key still gets nothing from the quote.
        $duplicate = DbErrors::pdo('23000', 1062, "Duplicate entry 'timed out' for key 't.name'");
        self::assertSame([], self::tags(new \RuntimeException('Saving failed: ' . $duplicate->getMessage(), 0, $duplicate)));
    }

    #[RequiresPhpExtension('mysqli')]
    public function testAWrapperQuotingAMysqliLockWaitTimeoutKeeps240sOrder(): void
    {
        $mysqli = DbErrors::mysqli('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction');
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], self::tags(new \RuntimeException('Saving failed: ' . $mysqli->getMessage(), 0, $mysqli)));
    }

    // ---- Nit 3: a short driver text is not a quote --------------------------------------------

    /** @return iterable<string, array{\Throwable, list<string>}> */
    public static function errorsAboveAShortSignal(): iterable
    {
        $signal = DbErrors::pdo('45000', 1644, 'Operation timed out');
        yield 'cURL 28 above a SIGNAL' => [new \RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received', 0, $signal), ['ETIMEDOUT']];
        $connection = DbErrors::pdo('45000', 1644, 'connection');
        yield 'a cache read error above a SIGNAL' => [new \RuntimeException('read error on connection to 10.0.0.9:6379', 0, $connection), []];
    }

    /** @param list<string> $tags */
    #[DataProvider('errorsAboveAShortSignal')]
    public function testAShortDriverTextIsNotAQuote(\Throwable $error, array $tags): void
    {
        self::assertTrue(self::counts($error));
        foreach ($tags as $tag) {
            self::assertContains($tag, self::tags($error));
        }
    }

    public function testCurl28AboveAShortSignalIsRetried(): void
    {
        $error = new \RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received', 0, DbErrors::pdo('45000', 1644, 'Operation timed out'));
        self::assertSame(2, $this->calls($this->retry(), $error, 'rtry:a=2;d=100;j=0;on=default', 1));
        self::assertSame([100], $this->sleeper->sleeps());
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function wrappersQuotingALongEnoughText(): iterable
    {
        $duplicate = DbErrors::pdo('23000', 1062, "Duplicate entry 'broken pipe' for key 't.name'");
        yield "the driver's text" => [new \RuntimeException("Could not save: Duplicate entry 'broken pipe' for key 't.name'", 0, $duplicate)];
        $signal = DbErrors::pdo('45000', 1644, 'Ledger refused: broken pipe');
        yield "a SIGNAL's text of 24 characters or more" => [new \RuntimeException('Posting failed: Ledger refused: broken pipe', 0, $signal)];
        yield "the answer's whole message" => [new \RuntimeException('Posting failed: ' . $signal->getMessage(), 0, $signal)];
    }

    #[DataProvider('wrappersQuotingALongEnoughText')]
    public function testAWrapperQuotingALongEnoughTextIsStillNotRead(\Throwable $error): void
    {
        self::assertFalse(self::counts($error));
        self::assertSame([], self::tags($error));
    }

    // ---- Nit 5: the edges that survived mutation ----------------------------------------------

    public function testOnlyProxySqlsWholeQueryTimeoutTextIsReadFor1907(): void
    {
        // DatabaseAnswer.php:65. A 1907 starting "Query execution was interrupted" without
        // ", query_timeout exceeded" is the server's answer, and its words are not read.
        $error = DbErrors::pdo('HY000', 1907, "Query execution was interrupted while reading 'broken pipe'");
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
        // ProxySQL's own text still reads.
        self::assertFalse(DatabaseAnswer::is(DbErrors::pdo('70100', 1907, 'Query execution was interrupted, query_timeout exceeded')));
    }

    public function testAnOracleErrorUnderAnAnswerClassIsAnAnswerWhateverItsNumber(): void
    {
        // DatabaseAnswer.php:109. PDO_OCI's number is Oracle's, so READ_ANYWAY (MySQL's 1105)
        // does not apply; its class 42 SQLSTATE still makes it an answer.
        $text = "OCIStmtExecute: ORA-01105: mount is incompatible with mounts by other instances 'broken pipe'";
        $error = PdoShaped::make("SQLSTATE[42000]: Syntax error or access violation: 1105 $text", '42000', ['42000', 1105, $text]);
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
    }

    public function testAPdoExceptionsStringCodeNamesADeadlockFirstAsIn240(): void
    {
        // DatabaseAnswer.php:266. No errorInfo: the string code 40001 is the SQLSTATE, and 2.4.0
        // gave DEADLOCK from it before the words' ETIMEDOUT.
        $error = PdoShaped::make('SQLSTATE[40001]: Serialization failure: 1205 Lock wait timeout exceeded; try restarting transaction', '40001', null);
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($error));
    }
}
