<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
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
 * The re-verification of PR #12 at 57cc02c (Gohany/Rtry#12), for 2.5.1.
 *
 * Nit 1: five of fourteen mutants of the OCI-call rule (DatabaseAnswer::OCI_CALL) survived the
 *   suite. Each is pinned here: the rule holds only at the very start of a text (not after a
 *   newline in a value, not anywhere in it), after leading whitespace, on a name of letters and
 *   underscores (PDO_OCI's OCI_NEED_DATA) and not on one with digits (OCI8).
 * Nit 2: Oracle errors that do not start with an OCI call's name keep MySQL's reading of their
 *   number, as on 2.4.1 and 2.5.0. Documented (README, "Database errors"), not changed in a
 *   patch release; pinned here so a later change is a deliberate one.
 *
 * The re-verification of PR #13 at fbfc977 (Gohany/Rtry#13), also for 2.5.1:
 * Nit 1: the mysqli test used SQLSTATE 23000, an answer before the OCI-call rule is asked, so
 *   it passed under every mutant. It now uses what a server sends: a value error (1366) under
 *   HY000.
 * Nit 2: the README named ORA-01040 and ORA-01053 as counting; every number in
 *   UnavailableEvidence::MYSQL_CODES but 4031 does. The README lists each, pinned below.
 * Nit 3: the CHANGELOG told a mutant's reading as something a release did.
 *
 * The re-verification of PR #14 at 7776726 (Gohany/Rtry#14), also for 2.5.1:
 * Finding 1: the CHANGELOG test found its entry by "## 2.5.1 (Unreleased)", so dating the
 *   heading for the tag turned it red. It finds "## 2.5.1".
 * Nit 2: both doc tests matched phrases across the hard line wrap. They collapse whitespace
 *   first.
 * Nit 3: the README test looked through the whole bullet, which names ORA-02055 twice; it reads
 *   the list only, up to "count toward the breaker".
 * Nit 4: the CHANGELOG said no release retried these texts; 2.4.0 did, by reading their words.
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

    /** @return iterable<string, array{string}> a mysqli 1366's texts for the edges below */
    public static function notOraclesTexts(): iterable
    {
        // A value error is what a server files under HY000 with the value in its text; under
        // 23000 the error is an answer before the OCI-call rule is asked, and nothing is pinned.
        yield 'inside a value' => ["Incorrect string value: 'OCIStmtExecute: connection refused' for column 'app'.'t'.'c' at row 1"];
        yield 'after a newline in a value' => ["Incorrect string value: 'x\nOCIStmtExecute: connection refused' for column 'app'.'t'.'c' at row 1"];
        yield 'a name with digits' => ["OCI8: Incorrect string value: 'connection refused' for column 'app'.'t'.'c' at row 1"];
    }

    #[DataProvider('notOraclesTexts')]
    #[RequiresPhpExtension('mysqli')]
    public function testTheSameTextsAsAMysqliErrorAreNotOracles(string $text): void
    {
        self::assertAnAnswer(DbErrors::mysqli('HY000', 1366, $text), 'mysqli');
        self::assertSame([1, []], self::attemptsAndSleeps('rtry:a=3;d=100;j=0;on=default', DbErrors::mysqli('HY000', 1366, $text)));
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

    // ---- Re-verification of #13 -------------------------------------------------------------

    /** @return array<int, string> every MySQL connection number Oracle's own number counts as, by ORA number */
    private static function oraclesCountingNumbers(): array
    {
        // 4031 counts only with MySQL's text; ORA-04031 does not (PatchReverifyFindingsTest).
        $numbers = [];
        foreach (array_diff(UnavailableEvidence::MYSQL_CODES, [4031]) as $n) {
            $numbers[$n] = sprintf('ORA-%05d', $n);
        }
        return $numbers;
    }

    public function testEveryOracleNumberThatCountsThroughPdoOciCounts(): void
    {
        foreach (self::oraclesCountingNumbers() as $n => $ora) {
            $text = "OCIStmtExecute: $ora: some Oracle text (ext/pdo_oci/oci_statement.c:159)";
            self::assertTrue(self::counts(PdoShaped::make("SQLSTATE[HY000]: General error: $n $text", 'HY000', ['HY000', $n, $text])), $ora);
        }
    }

    public function testTheReadmeListsEveryOracleNumberThatCounts(): void
    {
        // Up to "count toward the breaker": the list, not the remark after it, which names
        // ORA-02055 again.
        $readme = self::words((string) file_get_contents(__DIR__ . '/../README.md'));
        $start = strpos($readme, "- an Oracle error whose text does not start with an OCI call's name");
        self::assertNotFalse($start);
        $end = strpos($readme, 'count toward the breaker', $start);
        self::assertNotFalse($end);
        $list = substr($readme, $start, $end - $start);
        foreach (self::oraclesCountingNumbers() as $ora) {
            self::assertStringContainsString($ora, $list);
        }
    }

    public function testTheChangelogDoesNotTellAMutantAsARelease(): void
    {
        // The unanchored rule is a mutant: 2.4.1 and 2.5.0 read the text as an answer (above).
        // "## 2.5.1", not "## 2.5.1 (Unreleased)": the heading is dated when the release is tagged.
        $changelog = self::words((string) file_get_contents(__DIR__ . '/../CHANGELOG.md'));
        $start = strpos($changelog, '## 2.5.1');
        $end = strpos($changelog, '## 2.5.0');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $entry = substr($changelog, $start, $end - $start);
        self::assertStringNotContainsString('was read as Oracle', $entry);
        self::assertStringContainsString('would be read as Oracle', $entry);
        // 2.4.0 did retry these texts and open the circuit, by reading their words.
        self::assertStringNotContainsString('No release did that', $entry);
    }

    /** Whitespace collapsed, so a phrase matches however the paragraph is wrapped. */
    private static function words(string $text): string
    {
        return (string) preg_replace('~\s+~', ' ', $text);
    }
}
