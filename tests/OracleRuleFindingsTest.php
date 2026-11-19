<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
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
 * The re-verification of PR #12 at 57cc02c (Gohany/Rtry#12), for 2.5.1.
 *
 * Nit 1: five of fourteen mutants of the OCI-call rule (DatabaseAnswer::OCI_CALL) survived the
 *   suite. Each is pinned here: the rule holds only at the very start of a text (not after a
 *   newline in a value, not anywhere in it), after leading whitespace, on a name of letters and
 *   underscores (PDO_OCI's OCI_NEED_DATA) and not on one with digits (OCI8).
 * Nit 2: Oracle errors that do not start with an OCI call's name keep MySQL's reading of their
 *   number, as on 2.4.1 and 2.5.0. Documented (README, "Database errors"), not changed in a
 *   patch release; pinned here so a later change is a deliberate one.
 */
final class OracleRuleFindingsTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private static function counts(\Throwable $e): bool
    {
        $outcome = RtryAttemptOutcome::fromMetadata($e, RuleBasedFailureClassifier::default()->classify($e), self::T0);
        return (new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false);
    }

    /** @return array{int, list<int>} attempts run and the sleeps taken */
    private static function attemptsAndSleeps(string $spec, \Throwable $error): array
    {
        $clock = new FrozenClock(self::T0);
        $sleeper = new RecordingSleeper($clock);
        $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer(7));
        $ran = 0;
        $retry->tryOutcome(static function () use (&$ran, $error): never {
            $ran++;
            throw $error;
        }, (new RtryPolicyFactory())->fromSpec($spec));
        return [$ran, $sleeper->sleeps()];
    }

    /** @return array<string, \Throwable> a MySQL 1062 whose text is $text, in each PDO shape it reaches us */
    private static function duplicate(string $text): array
    {
        return [
            'hand-built' => new \PDOException($text, 1062),
            'from PDO\'s fields' => PdoShaped::make("SQLSTATE[HY000]: General error: 1062 $text", 'HY000', ['HY000', 1062, $text]),
        ];
    }

    /** @return iterable<string, array{string}> the 1062 texts each edge below pins */
    public static function notOraclesTexts(): iterable
    {
        yield 'inside a value' => ["Duplicate entry 'OCIStmtExecute: connection refused' for key 'u'"];
        yield 'after a newline in a value' => ["Duplicate entry 'x\nOCIStmtExecute: connection refused' for key 'u'"];
        yield 'a name with digits' => ["OCI8: Duplicate entry 'connection refused' for key 'u'"];
    }

    #[DataProvider('notOraclesTexts')]
    #[RequiresPhpExtension('mysqli')]
    public function testTheSameTextsAsAMysqliErrorAreNotOracles(string $text): void
    {
        self::assertAnAnswer(DbErrors::mysqli('23000', 1062, $text), 'mysqli');
        self::assertSame([1, []], self::attemptsAndSleeps('rtry:a=3;d=100;j=0;on=default', DbErrors::mysqli('23000', 1062, $text)));
    }

    private static function assertAnAnswer(\Throwable $error, string $what): void
    {
        self::assertTrue(DatabaseAnswer::is($error), $what);
        self::assertFalse(DatabaseAnswer::isConnectionError($error), $what);
        self::assertSame([], DatabaseAnswer::tags($error), $what);
        self::assertFalse(self::counts($error), $what);
    }

    // ---- Nit 1: the OCI-call rule, edge by edge --------------------------------------------

    public function testAnOciCallInsideAValueIsNotOracles(): void
    {
        // The ^ anchor: the name must start the text, not sit in a value it echoes.
        foreach (self::duplicate("Duplicate entry 'OCIStmtExecute: connection refused' for key 'u'") as $shape => $error) {
            self::assertAnAnswer($error, $shape);
        }
    }

    public function testAnOciCallAfterANewlineInAValueIsNotOracles(): void
    {
        // No multi-line matching: a newline in a value does not start a text.
        foreach (self::duplicate("Duplicate entry 'x\nOCIStmtExecute: connection refused' for key 'u'") as $shape => $error) {
            self::assertAnAnswer($error, $shape);
        }
    }

    public function testANameWithDigitsIsNotAnOciCall(): void
    {
        // PDO_OCI's call names are letters and underscores; "OCI8: " is someone else's text.
        foreach (self::duplicate("OCI8: Duplicate entry 'connection refused' for key 'u'") as $shape => $error) {
            self::assertAnAnswer($error, $shape);
        }
    }

    public function testPdoOcisOciNeedDataIsOracles(): void
    {
        // oci_statement.c:488 writes "OCI_NEED_DATA: OCI_NEED_DATA (...)": an underscore in the
        // name. Under code 1205 it is not MySQL's lock wait timeout.
        $text = 'OCI_NEED_DATA: OCI_NEED_DATA (ext/pdo_oci/oci_statement.c:605)';
        foreach ([
            'hand-built' => new \PDOException($text, 1205),
            'from PDO\'s fields' => PdoShaped::make("SQLSTATE[HY000]: General error: 1205 $text", 'HY000', ['HY000', 1205, $text]),
        ] as $shape => $error) {
            self::assertSame([], DatabaseAnswer::tags($error), $shape);
            self::assertFalse(DatabaseAnswer::isLockTimeout($error, 5), $shape);
        }
    }

    public function testAnOciCallAfterLeadingWhitespaceIsOracles(): void
    {
        $text = "\n  OCIStmtExecute: ORA-01205: not a data file - type is 77 (ext/pdo_oci/oci_statement.c:159)";
        $error = new \PDOException($text, 1205);
        self::assertSame([], DatabaseAnswer::tags($error));
        self::assertFalse(DatabaseAnswer::isLockTimeout($error, 5));
    }

    public function testAnOciCallInsideAValueRunsOnceAndOpensNothing(): void
    {
        // End to end: the spec, the error, the sleeps Retry takes.
        $spec = 'rtry:a=3;d=100;j=0;on=default';
        foreach (self::duplicate("Duplicate entry 'OCIStmtExecute: connection refused' for key 'u'") as $shape => $error) {
            self::assertSame([1, []], self::attemptsAndSleeps($spec, $error), $shape);
        }
        // PDO_OCI's own OCI_NEED_DATA under 1205 is not retried as a deadlock either.
        self::assertSame([1, []], self::attemptsAndSleeps($spec, new \PDOException('OCI_NEED_DATA: OCI_NEED_DATA (ext/pdo_oci/oci_statement.c:605)', 1205)));
    }

    // ---- Nit 2: Oracle outside the OCI-call rule keeps MySQL's reading (documented) --------

    public function testOracleNotStartingWithAnOciCallKeepsMysqlsReadingOfItsNumber(): void
    {
        // PDO_OCI's failed attach (oci_driver.c:778) and yajra/pdo-via-oci8's Oci8Exception
        // (code the ORA number, message "ORA-..."): ORA-01213 is read as MySQL's deadlock.
        foreach ([
            'pdo_oci_handle_factory' => PdoShaped::make('SQLSTATE[HY000]: pdo_oci_handle_factory: ORA-01213: MAXINSTANCES may not exceed 255 (ext/pdo_oci/oci_driver.c:778)', 1213, null),
            'yajra Oci8Exception' => new \PDOException('ORA-01213: MAXINSTANCES may not exceed 255', 1213),
        ] as $shape => $error) {
            self::assertSame(['DEADLOCK'], DatabaseAnswer::tags($error), $shape);
        }
    }

    public function testOraclesConnectionNumbersThroughPdoOciStillCount(): void
    {
        // ORA-01040 and ORA-01053 count as MySQL's 1040 and 1053, as on 2.4.0
        // (PatchReverifyFindingsTest pins ORA-01040 and ORA-02006 from PDO's fields).
        foreach ([1040 => 'ORA-01040: invalid character in password; logon denied', 1053 => 'ORA-01053: user storage address cannot be read'] as $n => $ora) {
            self::assertTrue(self::counts(PdoShaped::make("SQLSTATE[HY000]: OCISessionBegin: $ora (ext/pdo_oci/oci_driver.c:179)", $n, null)), (string) $n);
        }
    }
}
