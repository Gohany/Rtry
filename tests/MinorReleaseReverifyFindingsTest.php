<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\DatabaseAnswer;
use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Impl\HeaderWaits;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\DbErrors;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\PdoShaped;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hostile review of PR #11 at faed76f (Gohany/Rtry#11), fixed for 2.5.0.
 *
 * Nit 1: the CHANGELOG named two numbers that the hand-built PDOException moves; every number
 *   whose text is matched moves, each to what the same error does from PDO's fields (and as a
 *   mysqli_sql_exception). The table below pins each row the CHANGELOG now lists.
 * Nit 2: a hand-built message starting with "oci" ("Ocient loader: ...") was taken for PDO_OCI's
 *   text, so its MySQL number was dropped. PDO_OCI's text starts with the OCI call's name and a
 *   colon ("OCIStmtExecute: ORA-..."); only that is Oracle's now.
 * Nit 5: ten edges of the room and of the hand-built shape survived mutation; each is pinned.
 */
final class MinorReleaseReverifyFindingsTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private const MYSQL_IDLE = 'The client was disconnected by the server because of inactivity. See wait_timeout and interactive_timeout for configuring this behavior.';

    private static function counts(\Throwable $e): bool
    {
        $outcome = RtryAttemptOutcome::fromMetadata($e, RuleBasedFailureClassifier::default()->classify($e), self::T0);
        return (new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false);
    }

    /** A failure whose server said to come back at $notBefore, with no spread applied. */
    private static function told(int $notBefore): RtryAttemptOutcome
    {
        return RtryAttemptOutcome::fromMetadata(new \RuntimeException('503'), new FailureMetadata(503, [], [], null, $notBefore), self::T0);
    }

    // ---- Nit 5: the room above the server's time (RtryAttemptOutcome.php:139, :161-:166) -------

    public function testTheRoomEndsOneMillisecondBeforeDl(): void
    {
        $outcome = self::told(self::T0 + 29_000);
        self::assertSame(999, $outcome->roomAboveServerTimeMs(self::T0, self::T0 + 30_000));
        // A draw wider than the room (from a caller that did not scale it) is cut onto dl - 1 ms.
        self::assertSame(self::T0 + 29_999, $outcome->spreadAboveServerTime(self::T0, 5_000, self::T0 + 30_000)->notBeforeUnixMs());
        self::assertSame(self::T0 + 29_500, $outcome->spreadAboveServerTime(self::T0, 500, self::T0 + 30_000)->notBeforeUnixMs());
    }

    public function testTheRoomEndsOnTheCeilingItself(): void
    {
        $outcome = self::told(self::T0 + 29_000);
        self::assertSame(500, $outcome->roomAboveServerTimeMs(self::T0, null, 29_500));
        // A wait of exactly the ceiling fits it (RtryScheduler gives up only above it).
        self::assertSame(self::T0 + 29_500, $outcome->spreadAboveServerTime(self::T0, 5_000, null, 29_500)->notBeforeUnixMs());
        // The nearer of dl and the ceiling wins.
        self::assertSame(499, $outcome->roomAboveServerTimeMs(self::T0, self::T0 + 29_500, 29_500));
        self::assertSame(400, $outcome->roomAboveServerTimeMs(self::T0, self::T0 + 31_000, 29_400));
    }

    public function testTheRoomEndsOnTheEpochCeiling(): void
    {
        $outcome = self::told(HeaderWaits::MAX_EPOCH_MS - 10);
        self::assertSame(10, $outcome->roomAboveServerTimeMs(self::T0));
        self::assertSame(HeaderWaits::MAX_EPOCH_MS, $outcome->spreadAboveServerTime(self::T0, 5_000)->notBeforeUnixMs());
    }

    // ---- Nit 5: RtryScheduler::headerSpreadMs() (:133, :138) ----------------------------------

    public function testARoomWithoutTheServersWaitStillBoundsTheDraw(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=10s');
        $alone = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $alone[] = RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer($seed), null, 500);
            // With a wait whose bound (2 s) is wider than the room, the room is the bound either way.
            self::assertSame($alone[$seed - 1], RtryScheduler::headerSpreadMs($policy, 2, SeededRandom::randomizer($seed), 20_000, 500));
        }
        self::assertLessThanOrEqual(500, max($alone));
        self::assertGreaterThan(450, max($alone));
        self::assertLessThanOrEqual(3, count(array_keys($alone, 500, true)), 'scaled, not clipped');
    }

    public function testARoomOfOneMillisecondCanStillBeUsed(): void
    {
        // d=2 draws the next 4 ms backoff from [0, 4]; scaled into 1 ms, a whole-window draw is 1.
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=2');
        $spreads = [];
        for ($seed = 1; $seed <= 100; $seed++) {
            $spreads[] = RtryScheduler::headerSpreadMs($policy, 1, SeededRandom::randomizer($seed), 2_000, 1);
        }
        self::assertSame([0, 1], array_values(array_unique(array_merge([0, 1], $spreads))), 'only 0 and 1');
        self::assertContains(1, $spreads);
        self::assertContains(0, $spreads);
    }

    // ---- Nit 5: Retry passes its scheduler's ceiling into the room (Retry.php:178) -------------

    public function testASpecToldToWaitAlmostTheWholeCeilingSpreadsIntoWhatIsLeft(): void
    {
        // Retry-After: 86399 leaves 1 s under Retry's ceiling of a day; the policy's own window
        // before attempt 2 is 10 s. Scaled into that second, not piled on the ceiling.
        $ceiling = RtryScheduler::MAX_HEADER_WAIT_MS;
        $sleeps = [];
        for ($seed = 1; $seed <= 200; $seed++) {
            $clock = new FrozenClock(self::T0);
            $sleeper = new RecordingSleeper($clock);
            $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer($seed));
            $result = $retry->tryOutcome(static function (AttemptContextInterface $a): string {
                if ($a->attemptNumber() === 1) {
                    throw HttpFailure::status(429, ['Retry-After' => '86399']);
                }
                return 'ok';
            }, (new RtryPolicyFactory())->fromSpec('rtry:a=2;d=10s'));
            self::assertTrue($result->isSuccess(), "seed $seed");
            self::assertCount(1, $sleeper->sleeps(), "seed $seed");
            $sleeps[] = $sleeper->sleeps()[0];
        }
        self::assertGreaterThanOrEqual($ceiling - 1_000, min($sleeps));
        self::assertLessThanOrEqual($ceiling, max($sleeps));
        self::assertLessThanOrEqual(3, count(array_keys($sleeps, $ceiling, true)), 'no pile-up on the ceiling');
        self::assertGreaterThan(150, count(array_unique($sleeps)));
    }

    // ---- Nit 5: the hand-built shape is a PDOException with a non-zero int code (DatabaseAnswer.php:393)

    public function testAPdoExceptionWithAStringCodeDoesNotHaveItsMessageReadAsTheServersText(): void
    {
        // errorInfo carries the number and no text; the code is the SQLSTATE, as PDO sets it.
        $error = PdoShaped::make(self::MYSQL_IDLE, 'HY000', ['HY000', 4031, null]);
        self::assertFalse(DatabaseAnswer::isConnectionError($error));
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
    }

    public function testAPdoExceptionWithCodeZeroDoesNotHaveItsMessageReadAsTheServersText(): void
    {
        $error = PdoShaped::make(self::MYSQL_IDLE, 0, ['HY000', 4031, null]);
        self::assertFalse(DatabaseAnswer::isConnectionError($error));
        self::assertTrue(DatabaseAnswer::is($error));
        self::assertFalse(self::counts($error));
    }

    public function testOnlyAPdoExceptionHasItsWholeMessageReadAsTheServersText(): void
    {
        // A validation error that carries a SQLSTATE and an int code: its message is never read,
        // so its words do not make it a lock timeout.
        $error = new class ('canceling statement due to lock timeout is not a valid reason', 7) extends \InvalidArgumentException {
            public function getSqlState(): string
            {
                return '55P03';
            }
        };
        self::assertFalse(DatabaseAnswer::isLockTimeout($error, 5));
        self::assertTrue(DatabaseAnswer::isLockTimeout(new \PDOException('ERROR:  canceling statement due to lock timeout', 7), 5), 'the PDOException of the same text is');
    }

    // ---- Nit 2: only PDO_OCI's call name makes a text Oracle's --------------------------------

    public function testAHandBuiltMessageStartingWithOciIsNotOracles(): void
    {
        // Not the OCI call's case, or no colon straight after a name.
        foreach (['Ocient loader: ', 'Ocient: ', 'OCI loader: '] as $start) {
            $text = $start . "Duplicate entry 'connection refused' for key 'u'";
            foreach ([
                'hand-built' => new \PDOException($text, 1062),
                'from PDO\'s fields' => PdoShaped::make("SQLSTATE[HY000]: General error: 1062 $text", 'HY000', ['HY000', 1062, $text]),
            ] as $shape => $error) {
                self::assertTrue(DatabaseAnswer::is($error), "$start$shape");
                self::assertFalse(DatabaseAnswer::isConnectionError($error), "$start$shape");
                self::assertSame([], DatabaseAnswer::tags($error), "$start$shape");
                self::assertFalse(self::counts($error), "$start$shape");
            }
        }
    }

    public function testPdoOcisCallNameStillMakesTheNumberOracles(): void
    {
        // ORA-01205 is not MySQL's lock wait timeout, in every shape PDO_OCI's text reaches us.
        $text = 'OCIStmtExecute: ORA-01205: not a data file - type is 77 (ext/pdo_oci/oci_statement.c:159)';
        foreach ([
            'hand-built' => new \PDOException($text, 1205),
            'from PDO\'s fields' => PdoShaped::make("SQLSTATE[HY000]: General error: 1205 $text", 'HY000', ['HY000', 1205, $text]),
            'by description' => PdoShaped::make('SQLSTATE[HY000]: OCIStmtExecute: 1205 Lock wait timeout exceeded', 'HY000', null),
        ] as $shape => $error) {
            self::assertNotContains('DEADLOCK', DatabaseAnswer::tags($error), $shape);
            self::assertFalse(DatabaseAnswer::isLockTimeout($error, 5), $shape);
        }
    }

    // ---- Nit 1: every number whose text is matched reads as from PDO's fields -----------------

    /**
     * Each row: the number, the start of the server's text, the tags the hand-built shape now
     * has, whether it counts toward the breaker under `on=5xx` (with countRetryable off, as the
     * CHANGELOG states), and whether it is a lock timeout.
     *
     * @return iterable<string, array{int, string, list<string>, bool, bool}>
     */
    public static function matchedTexts(): iterable
    {
        yield '1205 lock wait timeout' => [1205, 'Lock wait timeout exceeded; try restarting transaction', ['ETIMEDOUT', 'DEADLOCK'], false, true];
        yield '1213 deadlock' => [1213, 'Deadlock found when trying to get lock; try restarting transaction', ['DEADLOCK'], false, false];
        yield '1614 XA deadlock' => [1614, 'XA_RBDEADLOCK: Transaction branch was rolled back: deadlock was detected', ['DEADLOCK'], false, false];
        yield '1639 debug sync timeout' => [1639, 'debug sync point wait timed out', ['ETIMEDOUT'], true, false];
        yield '3058 user-level lock deadlock' => [3058, 'Deadlock found when trying to get user-level lock; try rolling back transaction/releasing locks and restarting lock acquisition.', ['DEADLOCK'], false, false];
        yield '3132 locking service deadlock' => [3132, 'Deadlock found when trying to get locking service lock.', ['DEADLOCK'], false, false];
        yield '3133 service lock wait timeout' => [3133, 'Service lock wait timeout exceeded.', ['ETIMEDOUT', 'DEADLOCK'], false, true];
        yield '3699 regexp timeout' => [3699, 'Timeout exceeded in regular expression match.', ['ETIMEDOUT'], true, false];
        yield '3781 held statement timeout' => [3781, 'Timeout exceeded for held statement while new Group Replication primary member is applying backlog.', ['ETIMEDOUT'], true, false];
        yield '3840 redo log archiving timeout' => [3840, 'Redo log archiving start timed out.', ['ETIMEDOUT'], true, false];
        yield '4031 MySQL idle disconnect' => [4031, self::MYSQL_IDLE, [], true, false];
        yield '1907 ProxySQL query_timeout' => [1907, 'Query execution was interrupted, query_timeout exceeded', [], true, false];
        yield '1735 SingleStore leaf' => [1735, 'Unable to connect to leaf 10.0.0.7:3306 with user distributed', [], true, false];
    }

    /** @param list<string> $tags */
    #[DataProvider('matchedTexts')]
    public function testAHandBuiltErrorReadsAsTheSameErrorFromPdosFields(int $number, string $text, array $tags, bool $counts, bool $lockTimeout): void
    {
        $hand = new \PDOException($text, $number);
        $fields = DbErrors::pdo('HY000', $number, $text);
        foreach (['hand-built' => $hand, 'from PDO\'s fields' => $fields] as $shape => $error) {
            $read = array_values(array_intersect(DatabaseAnswer::tags($error), ['ETIMEDOUT', 'DEADLOCK']));
            sort($read);
            sort($tags);
            self::assertSame($tags, $read, $shape);
            self::assertSame($counts, self::counts($error), $shape);
            self::assertSame($lockTimeout, DatabaseAnswer::isLockTimeout($error, 5), $shape);
        }
        self::assertSame(DatabaseAnswer::is($fields), DatabaseAnswer::is($hand));
        self::assertSame(DatabaseAnswer::isConnectionError($fields), DatabaseAnswer::isConnectionError($hand));
    }

    public function testPostgresLockTimeoutUnderAnIntCodeIsALockTimeoutAndDoesNotCount(): void
    {
        $error = new \PDOException('ERROR:  canceling statement due to lock timeout', 55);
        self::assertTrue(DatabaseAnswer::isLockTimeout($error, 5));
        self::assertFalse(self::counts($error));
    }

    // ---- End to end: a spec, a hand-built driver error, the sleeps Retry takes ----------------

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

    public function testAHandBuiltRegexpTimeoutIsRetriedAndAHandBuiltOcientDuplicateIsNot(): void
    {
        $spec = 'rtry:a=3;d=100;j=0;on=default';
        self::assertSame([3, [100, 200]], self::attemptsAndSleeps($spec, new \PDOException('Timeout exceeded in regular expression match.', 3699)));
        self::assertSame([1, []], self::attemptsAndSleeps($spec, new \PDOException("Ocient loader: Duplicate entry 'connection refused' for key 'u'", 1062)));
    }
}
