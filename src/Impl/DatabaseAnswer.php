<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Rtry\Impl\Breaker\UnavailableEvidence;

/**
 * Whether a thrown error is a database's answer, read only from what the database error itself
 * carries, never from words a caller could have put in its message:
 *
 * - its SQLSTATE: a PDOException's errorInfo[0] or string code, getSqlState() (mysqli, Doctrine
 *   DBAL's driver exceptions), or "SQLSTATE[xxxxx]" at the very start of the message, where PDO
 *   writes it (a SQLSTATE anywhere else in a message is request text: a URL, echoed SQL);
 * - its error number: a PDOException's errorInfo[1] or int code, a mysqli_sql_exception's code,
 *   or the number PDO writes after that SQLSTATE ("General error: 1366", "[1049]");
 * - the driver's own text: errorInfo[2], a mysqli_sql_exception's message, or what follows
 *   PDO's prefix. Only its start is compared, which the server writes, never a value.
 *
 * The database answered when nothing there says connection (SQLSTATE class 08, 57P01-57P03,
 * 53300, or one of UnavailableEvidence::MYSQL_CODES) and
 *
 * - its SQLSTATE is in class 21 (cardinality), 22 (data), 23 (integrity constraint), 42
 *   (syntax or access rule), 44 (check option), 45 (MySQL's SIGNAL) or P0 (PostgreSQL's
 *   RAISE), or is 40001 or 40P01 (serialization failure, deadlock); or
 * - its number is a MySQL server error (1000 to 1999, 3000 to 5999). MySQL files many value
 *   errors under HY000 (1366 "Incorrect integer value: '...'", 1525, 1411, 1298, and the
 *   connect errors 1049 "Unknown database '...'" and 1045), so the class cannot tell. Except
 *   1105 "Unknown error", which Vitess, TiDB and proxies use for anything, outages included,
 *   1429 and 1430 (FEDERATED relaying its foreign source), and Oracle's ORA numbers, which
 *   PDO_OCI files under HY000 too (its text starts with the OCI call). Client errors (2000 to
 *   2999), proxies (ProxySQL's 9001 "Max connect timeout reached") and other databases'
 *   numbers fall outside and are read as before.
 *
 * Such an error's message echoes what the caller sent, so neither it nor the message of any
 * error wrapping it (unreadDepth()) is read for words. The contention and timeouts the server's
 * own text names keep their tags (tags()).
 *
 * @internal
 */
final class DatabaseAnswer
{
    private const ANSWER_CLASSES = ['21', '22', '23', '42', '44', '45', 'P0'];

    /** Serialization failure and PostgreSQL's deadlock: contention, the database answered. */
    private const CONTENTION_STATES = ['40001', '40P01'];

    /** MySQL server numbers that are not the server's own answer (see the class docblock). */
    private const READ_ANYWAY = [1105, 1429, 1430];

    /** MySQL's 1213 deadlock and 1205 lock wait timeout; SQL Server's 1205 deadlock victim. */
    private const DEADLOCK_NUMBERS = [1205, 1213];

    /**
     * MySQL server errors whose own text, not a value, names contention or a timeout: the start
     * of that text, the tags its words gave before 2.4.1 (now given by the number and that
     * start), and whether it is a lock timeout (ServiceFailureVerdict drops its ETIMEDOUT).
     *
     * @var array<int, array{string, list<string>, bool}>
     */
    private const MYSQL_TEXT_TAGS = [
        1205 => ['lock wait timeout exceeded', [FailureToken::DEADLOCK, FailureToken::ETIMEDOUT], true],
        1213 => ['deadlock found', [FailureToken::DEADLOCK], false],
        1614 => ['xa_rbdeadlock', [FailureToken::DEADLOCK], false],
        1639 => ['debug sync point wait timed out', [FailureToken::ETIMEDOUT], false],
        3058 => ['deadlock found', [FailureToken::DEADLOCK], false],
        3132 => ['deadlock found', [FailureToken::DEADLOCK], false],
        3133 => ['service lock wait timeout exceeded', [FailureToken::DEADLOCK, FailureToken::ETIMEDOUT], true],
        3699 => ['timeout exceeded in regular expression', [FailureToken::ETIMEDOUT], false],
        3781 => ['timeout exceeded for held statement', [FailureToken::ETIMEDOUT], false],
        3840 => ['redo log archiving start timed out', [FailureToken::ETIMEDOUT], false],
    ];

    /** PDO's prefix: "SQLSTATE[HY000]: General error: 1366 text", "SQLSTATE[HY000] [1049] text". */
    private const PDO_PREFIX = '~^SQLSTATE\[([0-9A-Za-z]{5})\](?:: [^:\n]*(?:: (\d+))?| \[(\d+)\])? ?(.*)~s';

    /** PostgreSQL's lock_timeout (55P03), as the server's text starts. */
    private const PG_LOCK_TIMEOUT = '~^(?:error:\s+)?canceling statement due to lock timeout~';

    /** Whether $t itself is a database's answer. */
    public static function is(\Throwable $t): bool
    {
        $id = self::identity($t);
        if ($id === null || self::isConnection($id)) {
            return false;
        }
        foreach ($id['states'] as $state) {
            if (in_array(substr($state, 0, 2), self::ANSWER_CLASSES, true) || in_array($state, self::CONTENTION_STATES, true)) {
                return true;
            }
        }
        $n = $id['number'];
        return $n !== null && !$id['oracle']
            && (($n >= 1000 && $n <= 1999) || ($n >= 3000 && $n <= 5999))
            && !in_array($n, UnavailableEvidence::MYSQL_CODES, true)
            && !in_array($n, self::READ_ANYWAY, true);
    }

    /**
     * How many errors, from the outermost along getPrevious(), are not read for words: the
     * deepest database answer within $maxDepth and every error that wraps it. 0 when none.
     */
    public static function unreadDepth(\Throwable $e, int $maxDepth): int
    {
        $unread = 0;
        for ($t = $e, $depth = 0; $t !== null && $depth < $maxDepth; $t = $t->getPrevious(), $depth++) {
            if (self::is($t)) {
                $unread = $depth + 1;
            }
        }
        return $unread;
    }

    /** Whether $t's own SQLSTATE or number says connection: class 08 and the like, or a MySQL connection number. */
    public static function isConnectionError(\Throwable $t): bool
    {
        $id = self::identity($t);
        return $id !== null && self::isConnection($id);
    }

    /**
     * DEADLOCK and ETIMEDOUT from $t's own SQLSTATE, number and the start of the server's text.
     *
     * @return list<string>
     */
    public static function tags(\Throwable $t): array
    {
        $id = self::identity($t);
        if ($id === null) {
            return [];
        }
        $tags = [];
        if (array_intersect($id['states'], self::CONTENTION_STATES) !== []) {
            $tags[] = FailureToken::DEADLOCK;
        }
        $n = $id['number'];
        if ($n !== null && !$id['oracle']) {
            if (in_array($n, self::DEADLOCK_NUMBERS, true)) {
                $tags[] = FailureToken::DEADLOCK;
            }
            if (isset(self::MYSQL_TEXT_TAGS[$n]) && str_starts_with($id['text'], self::MYSQL_TEXT_TAGS[$n][0])) {
                $tags = array_merge($tags, self::MYSQL_TEXT_TAGS[$n][1]);
            }
        }
        return array_values(array_unique($tags));
    }

    /**
     * Whether an error on $e's chain is a lock timeout: MySQL's 1205 or 3133 by number and the
     * start of the server's text, PostgreSQL's 55P03 "canceling statement due to lock timeout",
     * or an error with no database fields whose message starts with those words (a driver's
     * message rethrown).
     */
    public static function isLockTimeout(\Throwable $e, int $maxDepth): bool
    {
        for ($t = $e, $depth = 0; $t !== null && $depth < $maxDepth; $t = $t->getPrevious(), $depth++) {
            $id = self::identity($t);
            if ($id === null) {
                $message = strtolower(ltrim($t->getMessage()));
                if (str_starts_with($message, 'lock wait timeout exceeded') || preg_match(self::PG_LOCK_TIMEOUT, $message)) {
                    return true;
                }
                continue;
            }
            $n = $id['number'];
            if ($n !== null && !$id['oracle'] && (self::MYSQL_TEXT_TAGS[$n][2] ?? false) && str_starts_with($id['text'], self::MYSQL_TEXT_TAGS[$n][0])) {
                return true;
            }
            if (preg_match(self::PG_LOCK_TIMEOUT, $id['text'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array{states: list<string>, number: ?int, text: string, oracle: bool} $id */
    private static function isConnection(array $id): bool
    {
        foreach ($id['states'] as $state) {
            if (preg_match(UnavailableEvidence::SQLSTATE_PATTERN, $state)) {
                return true;
            }
        }
        return $id['number'] !== null && in_array($id['number'], UnavailableEvidence::MYSQL_CODES, true);
    }

    /**
     * What $t carries as a database error, or null when nothing. States are upper-cased; text
     * is the driver's own message, lower-cased ('' when not known).
     *
     * @return array{states: list<string>, number: ?int, text: string, oracle: bool}|null
     */
    private static function identity(\Throwable $t): ?array
    {
        $states = [];
        $number = null;
        $text = null;
        if ($t instanceof \PDOException) {
            $info = $t->errorInfo;
            if (is_array($info)) {
                if (isset($info[0]) && is_string($info[0]) && $info[0] !== '') {
                    $states[] = $info[0];
                }
                if (isset($info[1]) && (is_int($info[1]) || (is_string($info[1]) && ctype_digit($info[1]))) && (int) $info[1] !== 0) {
                    $number = (int) $info[1];
                }
                if (isset($info[2]) && is_string($info[2])) {
                    $text = $info[2];
                }
            }
            $code = $t->getCode();
            if (is_string($code) && $code !== '') {
                $states[] = $code;
            } elseif (is_int($code) && $code !== 0) {
                $number ??= $code;
            }
        }
        if ($t instanceof \mysqli_sql_exception) {
            $number = $t->getCode() !== 0 ? (int) $t->getCode() : null;
            $text = $t->getMessage();
        }
        if (method_exists($t, 'getSqlState')) {
            try {
                $state = $t->getSqlState();
                if (is_string($state) && $state !== '') {
                    $states[] = $state;
                }
            } catch (\Throwable) {
                // no SQLSTATE to go on
            }
        }
        if (preg_match(self::PDO_PREFIX, $t->getMessage(), $m)) {
            $states[] = $m[1];
            $n = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
            if ($n !== '') {
                $number ??= (int) $n;
            }
            $text ??= $m[4] ?? '';
        }
        if ($states === [] && $number === null) {
            return null;
        }
        $text = strtolower(ltrim($text ?? ''));
        return [
            'states' => array_values(array_unique(array_map('strtoupper', $states))),
            'number' => $number,
            'text' => $text,
            'oracle' => str_starts_with($text, 'oci'),
        ];
    }
}
