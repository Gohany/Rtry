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
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\Breaker\UnavailableEvidence;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Impl\Rules\MessageRegexRule;
use Gohany\Rtry\Tests\Support\DbalShaped;
use Gohany\Rtry\Tests\Support\DbErrors;
use Gohany\Rtry\Tests\Support\PdoShaped;
use Gohany\Rtry\Tests\Support\RecordingBreaker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The hostile review of PR #8 (head fc5363b, 2026-11-19), fixed before 2.4.1 is tagged.
 *
 * Blocker 1: MySQL files value errors under HY000 (1366, 1525, 1411, 1298, and the connect
 *   errors 1049 and 1045), so the SQLSTATE class could not tell that the server answered. A
 *   MySQL server error number decides instead.
 * Blocker 2: a SQLSTATE anywhere in any error's message switched off transport evidence. It is
 *   now read only from a database error's own fields, or where PDO writes it: at the start.
 * Should-fix 1: ETIMEDOUT beside DEADLOCK is dropped only for a lock timeout.
 * Should-fix 2 to 5 and the nits: the retry change's reach, SDKs whose status is only a code,
 *   tests that prove their names, and the edges that survived mutation.
 *
 * The PDO and mysqli shapes are the real ones from MySQL 8.0.43 (Support\DbErrors).
 */
final class TagReviewFindingsTest extends TestCase
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

    private static function classified(\Throwable $e): RtryAttemptOutcome
    {
        return RtryAttemptOutcome::fromMetadata($e, RuleBasedFailureClassifier::default()->classify($e), self::T0);
    }

    private static function counts(\Throwable $e): bool
    {
        return (new ServiceFailureVerdict(countRetryable: false))->isServiceFailure(self::classified($e), false);
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

    // ---- Blocker 1: MySQL's HY000 value errors -----------------------------------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function valueErrors(): iterable
    {
        foreach (['broken pipe', 'timed out', 'connection refused', 'ECONNRESET cURL error 7'] as $v) {
            yield "1366 '$v'" => [DbErrors::pdo('HY000', 1366, "Incorrect integer value: '$v' for column 'qty' at row 1")];
            yield "1525 '$v'" => [DbErrors::pdo('HY000', 1525, "Incorrect DATE value: '$v'")];
            yield "1411 '$v'" => [DbErrors::pdo('HY000', 1411, "Incorrect string value: '$v' for function uuid_to_bin")];
            yield "1298 '$v'" => [DbErrors::pdo('HY000', 1298, "Unknown or incorrect time zone: '$v'")];
            yield "connect 1049 '$v'" => [DbErrors::pdoConnect('HY000', 1049, "Unknown database 'tenant_$v'")];
            yield "connect 1045 '$v'" => [DbErrors::pdoConnect('HY000', 1045, "Access denied for user '$v'@'172.19.0.3' (using password: YES)")];
            yield "1366 '$v' by message alone" => [new \RuntimeException("SQLSTATE[HY000]: General error: 1366 Incorrect integer value: '$v' for column 'qty' at row 1")];
            yield "1049 '$v' by message alone" => [new \RuntimeException("SQLSTATE[HY000] [1049] Unknown database 'tenant_$v'")];
            yield "Laravel 1364 binding '$v'" => [DbErrors::laravel(DbErrors::pdo('HY000', 1364, "Field 'sku' doesn't have a default value"), 'insert into orders (name) values (?)', [$v])];
            yield "DBAL over 1366 '$v'" => [DbErrors::dbal(DbErrors::pdo('HY000', 1366, "Incorrect integer value: '$v' for column 'qty' at row 1"))];
            yield "a wrapper over 1298 '$v'" => [new \RuntimeException("Could not set the time zone: SQLSTATE[HY000]: General error: 1298 Unknown or incorrect time zone: '$v'", 0, DbErrors::pdo('HY000', 1298, "Unknown or incorrect time zone: '$v'"))];
        }
    }

    #[DataProvider('valueErrors')]
    public function testAValueErrorIsNotAnOutage(\Throwable $error): void
    {
        self::assertFalse(self::counts($error));
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure(TestOutcome::failure(null, [], null, $error), false), 'whatever classified it');
        self::assertSame([], self::tags($error));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function mysqliValueErrors(): iterable
    {
        yield '1366' => ['HY000', 1366, "Incorrect integer value: 'timed out' for column 'qty' at row 1"];
        yield '1298' => ['HY000', 1298, "Unknown or incorrect time zone: 'connection refused'"];
        yield 'connect 1049' => ['HY000', 1049, "Unknown database 'tenant_broken pipe'"];
        yield 'connect 1045' => ['HY000', 1045, "Access denied for user 'broken pipe'@'172.19.0.3' (using password: YES)"];
    }

    #[DataProvider('mysqliValueErrors')]
    #[RequiresPhpExtension('mysqli')]
    public function testAMysqliValueErrorIsNotAnOutage(string $state, int $number, string $text): void
    {
        $error = DbErrors::mysqli($state, $number, $text);
        self::assertFalse(self::counts($error));
        self::assertSame([], self::tags($error));
    }

    /** @return iterable<string, array{string}> */
    public static function specs(): iterable
    {
        yield 'on=5xx' => ['rtry:a=4;d=100;j=0;on=5xx'];
        yield 'on=default' => ['rtry:a=4;d=100;j=0;on=default'];
    }

    #[DataProvider('specs')]
    public function testAValueThatLooksLikeAnOutageNeitherRetriesNorOpensTheCircuit(string $spec): void
    {
        $breaker = $this->breaker();
        $retry = $this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict());
        $ran = 0;
        foreach (['timed out', 'broken pipe', 'connection refused'] as $v) {
            $ran += $this->calls($retry, DbErrors::pdo('HY000', 1366, "Incorrect integer value: '$v' for column 'qty' at row 1"), $spec, 1);
            $ran += $this->calls($retry, DbErrors::pdoConnect('HY000', 1049, "Unknown database 'tenant_$v'"), $spec, 1);
            $ran += $this->calls($retry, DbErrors::laravel(DbErrors::pdo('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'), 'update t set name = ?', [$v]), 'rtry:a=1;d=100;j=0;on=5xx', 1);
        }

        self::assertSame(9, $ran, 'each ran once, and none was refused');
        self::assertSame([], $this->sleeper->sleeps());
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    /** @return iterable<string, array{int, string, string, list<string>}> number, SQLSTATE, server text, the tags 2.4.0 gave from the words */
    public static function fixedTextServerErrors(): iterable
    {
        yield '1205 lock wait timeout' => [1205, 'HY000', 'Lock wait timeout exceeded; try restarting transaction', ['DEADLOCK', 'ETIMEDOUT']];
        yield '1213 deadlock' => [1213, '40001', 'Deadlock found when trying to get lock; try restarting transaction', ['DEADLOCK']];
        yield '1614 XA deadlock' => [1614, 'XA102', 'XA_RBDEADLOCK: Transaction branch was rolled back: deadlock was detected', ['DEADLOCK']];
        yield '3058 user lock deadlock' => [3058, 'HY000', 'Deadlock found when trying to get user-level lock; try rolling back transaction/releasing locks and restarting lock acquisition.', ['DEADLOCK']];
        yield '3132 locking service deadlock' => [3132, 'HY000', 'Deadlock found when trying to get locking service lock; try releasing locks and restarting lock acquisition.', ['DEADLOCK']];
        yield '3133 locking service timeout' => [3133, 'HY000', 'Service lock wait timeout exceeded.', ['DEADLOCK', 'ETIMEDOUT']];
        yield '3699 regexp timeout' => [3699, 'HY000', 'Timeout exceeded in regular expression match.', ['ETIMEDOUT']];
        yield '3781 group replication hold' => [3781, 'HY000', 'Timeout exceeded for held statement while new Group Replication primary member is applying backlog.', ['ETIMEDOUT']];
        yield '3840 redo log archive' => [3840, 'HY000', 'Redo log archiving start timed out', ['ETIMEDOUT']];
        yield '1639 debug sync' => [1639, 'HY000', 'debug sync point wait timed out', ['ETIMEDOUT']];
    }

    /** @param list<string> $tags */
    #[DataProvider('fixedTextServerErrors')]
    public function testAServerErrorWhoseOwnTextNamesContentionOrATimeoutKeepsItsTags(int $number, string $state, string $text, array $tags): void
    {
        $pdo = DbErrors::pdo($state, $number, $text);
        self::assertSame($tags, self::tags($pdo), 'PDO');
        self::assertSame($tags, self::tags(new \RuntimeException($pdo->getMessage())), 'by message alone');
        self::assertSame($tags, self::tags(DbErrors::laravel($pdo, 'update t set name = ? where id = ?', ['connection refused', '7'])), 'with a binding Laravel appended');
        self::assertSame($tags, self::tags(DbErrors::dbal($pdo)), 'through DBAL');
        self::assertSame($tags, self::tags(PdoShaped::make('Statement failed', $state, [$state, $number, $text])), 'by errorInfo, the message rewritten');

        // A lock timeout or a deadlock is the database answering; a timeout of its own is not.
        $outage = !in_array('DEADLOCK', $tags, true);
        self::assertSame($outage, self::counts($pdo));
        self::assertSame($outage, self::counts(DbErrors::laravel($pdo, 'update t set name = ?', ['connection refused'])));
    }

    /** @param list<string> $tags */
    #[DataProvider('fixedTextServerErrors')]
    #[RequiresPhpExtension('mysqli')]
    public function testAMysqliServerErrorWhoseOwnTextNamesContentionOrATimeoutKeepsItsTags(int $number, string $state, string $text, array $tags): void
    {
        self::assertSame($tags, self::tags(DbErrors::mysqli($state, $number, $text)));
    }

    public function testTheNumberAloneDoesNotGiveTheTagsOfAnotherServersText(): void
    {
        // pdo_dblib files SQL Server's errors under HY000 too; its 1205 is a deadlock victim.
        $dblib = DbErrors::pdo('HY000', 1205, 'Transaction (Process ID 52) was deadlocked on lock resources with another process and has been chosen as the deadlock victim. Rerun the transaction.');
        self::assertSame(['DEADLOCK'], self::tags($dblib));
        // A 3781 whose text is not MySQL's is just an answer.
        self::assertSame([], self::tags(DbErrors::pdo('HY000', 3781, "Unknown column 'timed out'")));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function outages(): iterable
    {
        yield 'connect refused (real)' => [DbErrors::pdoConnect('HY000', 2002, 'Connection refused')];
        yield 'connection killed (real)' => [DbErrors::pdo('HY000', 2006, 'MySQL server has gone away')];
        yield 'ProxySQL, backend unreachable (real)' => [DbErrors::pdo('HY000', 9001, 'Max connect timeout reached while reaching hostgroup 0 after 2000ms')];
        yield 'Vitess reports through 1105' => [DbErrors::pdo('HY000', 1105, 'target: shop.-.primary: vttablet: rpc error: code = Unavailable desc = connection refused')];
        yield 'FEDERATED cannot reach its source' => [DbErrors::pdo('HY000', 1429, 'Unable to connect to foreign data source: shop_remote')];
        yield 'class 08 with a server number' => [DbErrors::pdo('08S01', 1159, 'Got timeout reading communication packets')];
        yield 'too many connections' => [DbErrors::pdoConnect('08004', 1040, 'Too many connections')];
        yield 'Laravel over a refused connection' => [DbErrors::laravel(DbErrors::pdoConnect('HY000', 2002, 'Connection refused'), 'select 1', [])];
        yield 'DBAL over a lost connection' => [DbErrors::dbal(DbErrors::pdo('HY000', 2013, 'Lost connection to MySQL server during query'))];
    }

    #[DataProvider('outages')]
    public function testAnOutageStillCounts(\Throwable $error): void
    {
        self::assertTrue(self::counts($error));
    }

    public function testAConnectionErrorIsNoAnswerWhateverItsNumber(): void
    {
        // 1184 is in MySQL's server range, but its SQLSTATE says connection: its words are read.
        self::assertSame(['ECONNRESET'], self::tags(DbErrors::pdo('08S01', 1184, "Aborted connection 12 to db: 'shop' user: 'app' host: '10.0.0.7' (connection reset by peer)")));
        // errorInfo without a number leaves the driver's code to go on.
        self::assertTrue(self::counts(PdoShaped::make('General error', 2006, ['HY000', 0, 'x'])));
    }

    #[RequiresPhpExtension('mysqli')]
    public function testAMysqliOutageStillCounts(): void
    {
        self::assertTrue(self::counts(DbErrors::mysqli('HY000', 2002, 'Connection refused')));
        self::assertTrue(self::counts(DbErrors::mysqli('HY000', 9001, 'Max connect timeout reached while reaching hostgroup 0 after 2000ms')));
    }

    public function testProxySqlsTimeoutStillRetriesAndOpensTheCircuit(): void
    {
        $error = DbErrors::pdo('HY000', 9001, 'Max connect timeout reached while reaching hostgroup 0 after 2000ms');
        self::assertSame(['ETIMEDOUT'], self::tags($error));

        $result = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;j=0;on=default'));
        self::assertSame([4, GiveUpReason::AttemptsExhausted, [100, 200, 400]], [$result->attempts(), $result->giveUpReason(), $this->sleeper->sleeps()]);

        $breaker = $this->breaker();
        $ran = $this->calls($this->retry()->withGuard('db', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=3;d=100;j=0;on=5xx');
        self::assertSame(2, $ran, 'the third call is refused');
        self::assertSame(CircuitState::Open, $breaker->state('db'));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function numbers(): iterable
    {
        // A MySQL server error (1000 to 1999, 3000 to 5999) is the server answering. Client
        // errors (2000 to 2999), proxies (ProxySQL's 9001, RDS Proxy's 9501), TiDB, SQLite's
        // and PostgreSQL's numbers and negative SQLCODEs keep being read.
        foreach ([1000 => false, 1999 => false, 3000 => false, 5999 => false, 999 => true, 2000 => true, 2014 => true, 2999 => true, 6000 => true, 9001 => true, 9501 => true, 7 => true, 1 => true, -803 => true, 0 => true] as $n => $read) {
            yield (string) $n => [$n, $read];
        }
    }

    #[DataProvider('numbers')]
    public function testTheServerRangeDecides(int $number, bool $read): void
    {
        self::assertSame($read, UnavailableEvidence::in(DbErrors::pdo('HY000', $number, "x 'connection refused'")));
        self::assertSame($read, UnavailableEvidence::in(new \RuntimeException("SQLSTATE[HY000]: General error: $number x 'connection refused'")), 'by message alone');
    }

    public function testOraclesHy000ErrorsAreStillRead(): void
    {
        // PDO_OCI files every ORA error under HY000 with the ORA number; ORA-03156 is in
        // MySQL's server range, but its text is OCI's, not MySQL's.
        $ora = DbErrors::pdo('HY000', 3156, 'OCIStmtExecute: ORA-03156: OCI call timed out (ext/pdo_oci/oci_statement.c:159)');
        self::assertSame(['ETIMEDOUT'], self::tags($ora));
        self::assertSame(['ETIMEDOUT'], self::tags(new \RuntimeException($ora->getMessage())));
        self::assertSame(['ETIMEDOUT'], self::tags(PdoShaped::make('SQLSTATE[HY000] [3156] OCISessionBegin: ORA-03156: OCI call timed out', 3156, null)), 'a connect error');
        self::assertSame(['ETIMEDOUT'], self::tags(PdoShaped::make('Statement failed: OCI call timed out', 'HY000', ['HY000', 3156, 'OCIStmtExecute: ORA-03156: OCI call timed out'])), 'by errorInfo, the message rewritten');
    }

    // ---- Blocker 2: request text cannot switch transport evidence off -----------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function outagesCarryingRequestText(): iterable
    {
        yield 'a PHP stream warning' => [new \ErrorException('file_get_contents(http://127.0.0.1:9/search?q=SQLSTATE[23000]): Failed to open stream: Connection refused', 0, E_WARNING)];
        yield 'a connection error with its SQL appended' => [new \RuntimeException("MySQL server has gone away [INSERT INTO t VALUES ('SQLSTATE[23000]')]")];
        yield 'a message-only connection error echoing a name' => [new \RuntimeException('SQLSTATE[08006] [7] connection to server at "db" (10.0.0.5), port 5432 failed: FATAL:  database "SQLSTATE[23000]" does not exist')];
        yield 'a transport type whose message starts with a SQLSTATE' => [new TransportShaped('SQLSTATE[23000]: Duplicate entry')];
        yield 'a transport type with getSqlState()' => [new TransportShapedWithState('Idle timeout reached', '23000')];
        yield 'a lowercase sqlstate at the start (PDO writes SQLSTATE)' => [new \RuntimeException('sqlstate[23000]: connection refused')];
    }

    #[DataProvider('outagesCarryingRequestText')]
    public function testRequestTextCannotSwitchOffTransportEvidence(\Throwable $error): void
    {
        self::assertTrue(self::counts($error));
        self::assertTrue(UnavailableEvidence::in($error));
    }

    public function testARefusedConnectionWhoseUrlCarriesASqlstateRetriesAndReopensTheCircuit(): void
    {
        $error = new \ErrorException('file_get_contents(http://127.0.0.1:9/search?q=SQLSTATE[23000]): Failed to open stream: Connection refused', 0, E_WARNING);
        self::assertSame(['ECONNREFUSED'], self::tags($error));

        $result = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;on=default'));
        self::assertSame([3, GiveUpReason::AttemptsExhausted, [100, 200]], [$result->attempts(), $result->giveUpReason(), $this->sleeper->sleeps()]);

        $breaker = $this->breaker();
        self::assertSame(2, $this->calls($this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=3;d=100;j=0;on=5xx'));
        self::assertSame(CircuitState::Open, $breaker->state('api'));

        // As the half-open probe, it reopens the circuit.
        $this->clock->advanceMs(5_000);
        $this->calls($this->retry()->withGuard('api', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=3;d=100;j=0;on=5xx', 1);
        self::assertSame(CircuitState::Open, $breaker->state('api'));
    }

    public function testPdosOwnSqlstateComesFirst(): void
    {
        // PDO's order: the real SQLSTATE at the start, an injected one inside the echoed value.
        $error = new \RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'SQLSTATE[08006] broken pipe' for key 't.name'");
        self::assertFalse(self::counts($error));
        self::assertSame([], self::tags($error));
    }

    // ---- The chain: what wraps an answer, and what an answer wraps ----------------------------

    public function testAnOutageBelowAnAnswerStillCounts(): void
    {
        $lost = DbErrors::pdo('HY000', 2006, 'MySQL server has gone away');
        self::assertTrue(self::counts(new DbalShaped('An exception occurred: duplicate', 1062, '23000', $lost)), 'a driver connection error');
        self::assertTrue(self::counts(new DbalShaped('An exception occurred: duplicate', 1062, '23000', new TransportShaped('x'))), 'a transport type');
        self::assertSame(['NETWORK_ERROR'], self::tags(new DbalShaped('An exception occurred: duplicate', 1062, '23000', new TransportShaped('x'))));
        // A connection SQLSTATE on an error wrapping an answer still counts: evidence from fields
        // is read at any depth, answer or not.
        self::assertTrue(self::counts(new DbalShaped('An exception occurred in the driver: x', 0, '08S01', DbErrors::pdo('23000', 1062, "Duplicate entry 'a' for key 'u'"))));
        // Words below the deepest answer are read; only the answer and what wraps it are not.
        self::assertTrue(self::counts(PdoShaped::make('SQLSTATE[23000]: Integrity constraint violation: 1062 x', '23000', ['23000', 1062, 'x'], new \RuntimeException('connection refused'))));
        self::assertSame(['ECONNREFUSED'], self::tags(PdoShaped::make('SQLSTATE[23000]: Integrity constraint violation: 1062 x', '23000', ['23000', 1062, 'x'], new \RuntimeException('connection refused'))));
    }

    #[RequiresPhpExtension('mysqli')]
    public function testAFrameworkErrorOverAMysqliAnswerIsNotRead(): void
    {
        // CodeIgniter 4: new DatabaseException($e->getMessage(), $e->getCode(), $e).
        $mysqli = DbErrors::mysqli('23000', 1062, "Duplicate entry 'broken pipe' for key 't.name'");
        $error = new \RuntimeException($mysqli->getMessage(), $mysqli->getCode(), $mysqli);
        self::assertFalse(self::counts($error));
        self::assertSame([], self::tags($error));
    }

    public function testAFrameworkErrorWithOnlyTheCodeIsStillRead(): void
    {
        // Known gap (README): a code alone is never taken as a database's number.
        self::assertTrue(self::counts(new \RuntimeException("Duplicate entry 'broken pipe' for key 't.name'", 1062)));
    }

    // ---- Should-fix 1: only a lock timeout loses its ETIMEDOUT ------------------------------

    public function testATimeoutThatNamesADeadlockStillCounts(): void
    {
        $error = new \RuntimeException("Read timed out after 30s calling inventory-service for sku 'deadlock'");
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($error));
        self::assertTrue(self::counts($error));

        $breaker = $this->breaker();
        self::assertSame(2, $this->calls($this->retry()->withGuard('inventory', null, $breaker, new ServiceFailureVerdict()), $error, 'rtry:a=3;d=100;j=0;on=5xx'));
        self::assertSame(CircuitState::Open, $breaker->state('inventory'));

        $recording = new RecordingBreaker();
        $this->calls($this->retry()->withGuard('inventory', null, $recording, new ServiceFailureVerdict(countRetryable: false)), $error, 'rtry:a=3;d=100;j=0;on=default', 1);
        self::assertSame(['recordFailure', 'recordFailure', 'recordFailure'], $recording->reports);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function lockTimeouts(): iterable
    {
        $mysql = DbErrors::pdo('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction');
        yield 'MySQL 1205 (real)' => [$mysql];
        yield 'MySQL 1205 by message alone' => [new \RuntimeException($mysql->getMessage())];
        yield 'MySQL 1205, a mysqli message rethrown' => [new \RuntimeException('Lock wait timeout exceeded; try restarting transaction')];
        yield 'MySQL 1205 with a binding Laravel appended' => [DbErrors::laravel($mysql, 'update t set name = ?', ['timed out'])];
        yield 'MySQL 3133' => [DbErrors::pdo('HY000', 3133, 'Service lock wait timeout exceeded.')];
        $pg = DbErrors::pdo('55P03', 7, 'ERROR:  canceling statement due to lock timeout');
        yield 'PostgreSQL 55P03' => [$pg];
        yield 'PostgreSQL 55P03 by message alone' => [new \RuntimeException($pg->getMessage())];
    }

    #[DataProvider('lockTimeouts')]
    public function testALockTimeoutIsNotAnOutage(\Throwable $error): void
    {
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], self::tags($error), 'on=DEADLOCK and on=ETIMEDOUT still match it');
        self::assertFalse(self::counts($error));
    }

    public function testOnlyEtimedoutIsDroppedAndOnlyForALockTimeout(): void
    {
        $verdict = new ServiceFailureVerdict(countRetryable: false);
        $lock = DbErrors::pdo('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction');
        self::assertFalse($verdict->isServiceFailure(RtryAttemptOutcome::failure($lock, null, ['DEADLOCK', 'ETIMEDOUT']), false));
        foreach (['NETWORK_ERROR', 'ECONNRESET', 'ECONNREFUSED', 'RATE_LIMITED'] as $tag) {
            self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure($lock, null, ['DEADLOCK', 'ETIMEDOUT', $tag]), false), $tag);
        }
        self::assertTrue($verdict->isServiceFailure(RtryAttemptOutcome::failure(new \RuntimeException('x'), null, ['DEADLOCK', 'ETIMEDOUT']), false), 'not a lock timeout');
        self::assertTrue($verdict->isServiceFailure(TestOutcome::failure(null, ['DEADLOCK', 'ETIMEDOUT']), false), 'no error at all');
    }

    // ---- Should-fix 2: a rule brings back the retry of an answering timeout -------------------

    public function testARuleRestoresTheRetryOfATimeoutTheDatabaseAnswered(): void
    {
        $error = PdoShaped::make("SQLSTATE[42000]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]A timeout occurred while waiting for memory resources to execute the query in resource pool 'default' (8645).", '42000', ['42000', 8645, 'A timeout occurred while waiting for memory resources']);
        $spec = 'rtry:a=4;d=100;j=0;on=default';

        $once = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec($spec));
        self::assertSame([1, GiveUpReason::NotRetryable], [$once->attempts(), $once->giveUpReason()]);

        $classifier = RuleBasedFailureClassifier::default()->addRule(new MessageRegexRule('~A timeout occurred while waiting for memory~', null, ['ETIMEDOUT']));
        $again = $this->retry()->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory(null, $classifier))->fromSpec($spec));
        self::assertSame([4, GiveUpReason::AttemptsExhausted, [100, 200, 400]], [$again->attempts(), $again->giveUpReason(), $this->sleeper->sleeps()]);
    }

    // ---- Should-fix 3: an SDK whose status is only the exception code -----------------------

    public function testAnSdkThatCarriesTheStatusOnlyAsTheCodeNeedsAReader(): void
    {
        // elasticsearch-php 7: the response body as the message, the status as the code.
        $error = new \RuntimeException('{"error":{"root_cause":[{"type":"query_shard_exception","reason":"Failed to parse query [connection refused AND]"}]},"status":400}', 400);
        $reader = new class implements ErrorResponseReaderInterface {
            public function read(\Throwable $e): ?ErrorResponse
            {
                return str_starts_with($e->getMessage(), '{"error"') ? new ErrorResponse((int) $e->getCode()) : null;
            }
        };

        self::assertTrue(self::counts($error), 'without a reader its words are read (README, "Responses Rtry cannot find")');
        $outcome = RtryAttemptOutcome::fromMetadata($error, RuleBasedFailureClassifier::default($reader)->classify($error), self::T0);
        self::assertFalse((new ServiceFailureVerdict([], false, $reader))->isServiceFailure($outcome, false));
    }

    // ---- Should-fix 5: SQLSTATE classes and fields --------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function otherClasses(): iterable
    {
        foreach (['01000', '02000', '07001', '0A000', '20000', '24000', '25006', '26000', '27000', '28000', '2D000', '34000', '3D000', '3F000', '40002', '40003', '53100', '54000', '55000', '55P03', '57014', '58000', 'HY000', 'XA102'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('otherClasses')]
    public function testAnErrorInAnotherSqlstateClassIsStillRead(string $state): void
    {
        self::assertTrue(UnavailableEvidence::in(new DbalShaped('connection refused', 0, $state)), 'getSQLState()');
        self::assertTrue(UnavailableEvidence::in(PdoShaped::make('connection refused', $state, [$state, 0, 'connection refused'])), 'errorInfo and code');
        self::assertTrue(UnavailableEvidence::in(new \RuntimeException("SQLSTATE[$state]: General error: connection refused")), 'message');
        self::assertContains('ECONNREFUSED', self::tags(new DbalShaped('connection refused', 0, $state)));
    }

    /** @return iterable<string, array{string}> */
    public static function answerStates(): iterable
    {
        foreach (['21000', '22001', '23000', '23505', '42000', '42S22', '44000', '45000', 'P0001', '40001', '40P01'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('answerStates')]
    public function testAnAnswerStateIsAnAnswerFromEachField(string $state): void
    {
        self::assertFalse(UnavailableEvidence::in(new DbalShaped('connection refused', 0, $state)), 'getSQLState()');
        self::assertFalse(UnavailableEvidence::in(PdoShaped::make('connection refused', $state, null)), 'code');
        self::assertFalse(UnavailableEvidence::in(PdoShaped::make('connection refused', 0, [$state, 0, 'x'])), 'errorInfo');
        self::assertFalse(UnavailableEvidence::in(new \RuntimeException("SQLSTATE[$state]: x: 0 connection refused")), 'message start');
    }

    public function testADeadlockByMessageAloneKeepsItsTag(): void
    {
        self::assertSame(['DEADLOCK'], self::tags(new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')));
        self::assertSame(['DEADLOCK'], self::tags(new \RuntimeException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected')));
        self::assertSame(['DEADLOCK'], self::tags(new \RuntimeException('SQLSTATE[40001]: Serialization failure: 7 ERROR:  could not serialize access due to concurrent update')));
    }

    public function testASqlstateFromAFieldIsUpperCased(): void
    {
        self::assertFalse(UnavailableEvidence::in(PdoShaped::make('connection refused', 0, ['p0001', 0, 'x'])));
        self::assertSame(['DEADLOCK'], self::tags(PdoShaped::make('x', 0, ['40p01', 7, 'x'])));
        self::assertTrue(UnavailableEvidence::in(new DbalShaped('An exception occurred in the driver: x', 0, '08s01')));
    }

    public function testTheTypeAndDriverChecksComeBeforeTheAnswer(): void
    {
        self::assertTrue(UnavailableEvidence::in(new TransportShapedWithState('x', '23000')));
        self::assertSame(['NETWORK_ERROR'], self::tags(new TransportShapedWithState('x', '23000')));
        // A driver's connection number outvotes an answering SQLSTATE: the outage wins.
        self::assertTrue(UnavailableEvidence::in(PdoShaped::make('SQLSTATE[23000]: Integrity constraint violation: 2006 x', 2006, null)));
        // A deadlock type on an answer still tags.
        self::assertSame(['DEADLOCK'], self::tags(PdoShaped::make('x', '23000', ['40001', 1213, 'x'])));
    }

    // ---- Nits -------------------------------------------------------------------------------

    public function testAConnectionSqlstateFromGetSqlStateCounts(): void
    {
        // Doctrine DBAL's DriverException with a neutral message.
        self::assertTrue(UnavailableEvidence::in(new DbalShaped('An exception occurred in the driver: x', 0, '08S01')));
        self::assertTrue(UnavailableEvidence::in(new DbalShaped('An exception occurred in the driver: x', 0, '57P01')));
        self::assertFalse(UnavailableEvidence::in(new DbalShaped('An exception occurred in the driver: x', 0, 'HY000')));
    }

    /** @return iterable<string, array{class-string}> */
    public static function vendorTransportTypes(): iterable
    {
        require_once __DIR__ . '/Support/third-party-types.php';
        foreach (['GuzzleHttp\\Exception\\ConnectException', 'Doctrine\\DBAL\\Exception\\ConnectionException', 'Doctrine\\DBAL\\Exception\\ConnectionLost', 'Predis\\Connection\\ConnectionException'] as $class) {
            /** @var class-string $class */
            yield $class => [$class];
        }
    }

    /** @param class-string $class */
    #[DataProvider('vendorTransportTypes')]
    public function testEachVendorTransportTypeCountsAlone(string $class): void
    {
        $error = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(\Throwable::class, $error);
        self::assertTrue(UnavailableEvidence::in($error));
        self::assertTrue(UnavailableEvidence::in(new \RuntimeException('Saving failed', 0, $error)), 'on the chain');
    }

    public function testDoctrinesDeadlockTypeTags(): void
    {
        require_once __DIR__ . '/Support/third-party-types.php';
        $error = (new \ReflectionClass('Doctrine\\DBAL\\Exception\\DeadlockException'))->newInstanceWithoutConstructor();
        self::assertInstanceOf(\Throwable::class, $error);
        self::assertSame(['DEADLOCK'], self::tags($error));
    }

    /** @return iterable<string, array{string}> */
    public static function phrases(): iterable
    {
        $phrases = (new \ReflectionClassConstant(UnavailableEvidence::class, 'PHRASES'))->getValue();
        self::assertIsArray($phrases);
        foreach ($phrases as $phrase) {
            yield $phrase => [(string) $phrase];
        }
    }

    #[DataProvider('phrases')]
    public function testEachPhraseCountsAlone(string $phrase): void
    {
        self::assertTrue(UnavailableEvidence::in(new \RuntimeException('upstream: ' . strtoupper($phrase) . ' (see log)')));
        self::assertFalse(UnavailableEvidence::in(new \RuntimeException('upstream: ' . substr($phrase, 0, -1) . '_ (see log)')), 'and only the whole phrase');
    }
}

/** @internal a Symfony HttpClient transport error */
final class TransportShaped extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
{
}

/** @internal a transport error that also has getSqlState() */
final class TransportShapedWithState extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
{
    public function __construct(string $message, private string $state)
    {
        parent::__construct($message);
    }

    public function getSqlState(): string
    {
        return $this->state;
    }
}
