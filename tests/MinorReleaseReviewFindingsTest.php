<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\Breaker\UnavailableEvidence;
use Gohany\Rtry\Impl\DatabaseAnswer;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\DbErrors;
use Gohany\Rtry\Tests\Support\PdoShaped;
use PHPUnit\Framework\TestCase;

/**
 * The hostile review of PR #10 at 0960d39 (Gohany/Rtry#10), fixed for 2.5.0. The tag blocker
 * is BreakerKeyNotInMessageTest's open-circuit test; should-fix 1 (the spread at dl) and the
 * jitterWindowMs() pins are in HeaderSpreadBoundTest.
 *
 * Nit 4: a PDOException built from a driver's error (no errorInfo, the number as its int code,
 *   the server's text as its whole message, as `new PDOException($mysqli->error,
 *   $mysqli->errno)`) had no text to read, so MySQL's 4031 idle disconnect was an answer.
 * Nit 5: the database edges that survived mutation are pinned.
 */
final class MinorReleaseReviewFindingsTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private const MYSQL_IDLE = 'The client was disconnected by the server because of inactivity. See wait_timeout and interactive_timeout for configuring this behavior.';
    private const MARIADB_TRIGGER = "Referenced trigger 'tenant_acme_audit' for the given action time and event type does not exist";

    private static function counts(\Throwable $e): bool
    {
        $outcome = RtryAttemptOutcome::fromMetadata($e, RuleBasedFailureClassifier::default()->classify($e), self::T0);
        return (new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false);
    }

    // ---- Nit 4: a PDOException built from a driver's error ------------------------------------

    public function testAPdoExceptionBuiltFromMysqlsIdleDisconnectCounts(): void
    {
        $error = new \PDOException(self::MYSQL_IDLE, 4031);
        self::assertTrue(DatabaseAnswer::isConnectionError($error));
        self::assertFalse(DatabaseAnswer::is($error));
        self::assertTrue(self::counts($error));
    }

    public function testAPdoExceptionBuiltFromMariaDbs4031IsStillAnAnswer(): void
    {
        $error = new \PDOException(self::MARIADB_TRIGGER, 4031);
        self::assertFalse(DatabaseAnswer::isConnectionError($error));
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
    }

    public function testAPdoExceptionBuiltFromALockWaitTimeoutReadsAsMysqlisDoes(): void
    {
        $text = 'Lock wait timeout exceeded; try restarting transaction';
        self::assertSame(['ETIMEDOUT', 'DEADLOCK'], DatabaseAnswer::tags(new \PDOException($text, 1205)));
        self::assertTrue(DatabaseAnswer::isLockTimeout(new \PDOException($text, 1205), 5));
    }

    public function testAPdoExceptionWithPdosPrefixOrErrorInfoIsReadAsBefore(): void
    {
        // The whole message is the driver's text only when there is no other: no prefix, no errorInfo[2].
        self::assertTrue(DatabaseAnswer::is(PdoShaped::make(self::MYSQL_IDLE, 4031, ['HY000', 4031, 'Statement failed'])));
        self::assertFalse(DatabaseAnswer::isConnectionError(new \PDOException('Statement failed: ' . self::MYSQL_IDLE, 4031)));
    }

    // ---- Nit 5: the edges that survived mutation ----------------------------------------------

    public function testOnlyMysqlsWholeIdleDisconnectTextMakes4031AConnectionError(): void
    {
        // UnavailableEvidence.php:55: the start of MySQL's text, not "The client".
        $error = DbErrors::pdo('HY000', 4031, "The client 'app'@'10.0.0.5' is blocked because of many connection errors");
        self::assertFalse(DatabaseAnswer::isConnectionError($error));
        self::assertFalse(UnavailableEvidence::in(new \RuntimeException($error->getMessage())));
    }

    public function testPdosConnectFormOf4031InsideAMessageCounts(): void
    {
        // UnavailableEvidence.php:157: "SQLSTATE[HY000] [4031] The client was ..." quoted in a message.
        self::assertTrue(UnavailableEvidence::in(new \RuntimeException('Job 7 failed: SQLSTATE[HY000] [4031] ' . self::MYSQL_IDLE)));
    }

    public function testTheQuoteMinimumIsTwentyFourCharacters(): void
    {
        // DatabaseAnswer.php:80. A 23-character driver text is not a quote, so the wrapper's
        // words ("epipe") are read; a 24-character one is, so they are not.
        $short = "Unknown locale: 'epipe'";
        $long = "Unknown database 'epipe'";
        self::assertSame(23, strlen($short));
        self::assertSame(24, strlen($long));
        self::assertTrue(self::counts(new \RuntimeException("Locale failed: $short", 0, DbErrors::pdo('45000', 1644, $short))));
        self::assertFalse(self::counts(new \RuntimeException("Locale failed: $long", 0, DbErrors::pdo('45000', 1644, $long))));
    }

    public function testAnOracleNumberNeverGetsMysqlsTextTags(): void
    {
        // DatabaseAnswer.php:208: under PDO_OCI the number is Oracle's, whatever the text says.
        $error = PdoShaped::make('SQLSTATE[HY000]: OCIStmtExecute: 1213 Deadlock found when trying to get lock', 'HY000', null);
        self::assertSame([], DatabaseAnswer::tags($error));
        // MySQL's own 1213 still gets it.
        self::assertSame(['DEADLOCK'], DatabaseAnswer::tags(PdoShaped::make('SQLSTATE[HY000]: General error: 1213 Deadlock found when trying to get lock', 'HY000', null)));
    }
}
