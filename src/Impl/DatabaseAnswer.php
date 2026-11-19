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
 * - the driver's own text: errorInfo[2], a mysqli_sql_exception's message, and what follows
 *   PDO's prefix. Only their start is compared, which the server writes, never a value.
 *
 * The message of a LogicException or an \Error (a bug or a validation error) is never read, so
 * "SQLSTATE[08006] is not a valid coupon code" is nothing here; its fields still are.
 *
 * The database answered when nothing there says connection (SQLSTATE class 08, 57P01-57P03,
 * 53300, or a MySQL connection number: UnavailableEvidence::isConnectionNumber()), its number is
 * not one whose text is someone else's (READ_ANYWAY, PROXY_TEXTS), and
 *
 * - its SQLSTATE is in class 21 (cardinality), 22 (data), 23 (integrity constraint), 42
 *   (syntax or access rule), 44 (check option), 45 (MySQL's SIGNAL) or P0 (PostgreSQL's
 *   RAISE), or is 40001 or 40P01 (serialization failure, deadlock); or
 * - its number is a MySQL server error (1000 to 1999, 3000 to 5999). MySQL files many value
 *   errors under HY000 (1366 "Incorrect integer value: '...'", 1525, 1411, 1298, and the
 *   connect errors 1049 "Unknown database '...'" and 1045), so the class cannot tell. Not
 *   Oracle's ORA numbers, which PDO_OCI files under HY000 too (its text, or the description
 *   PDO writes before it, starts with the OCI call). Client errors (2000 to 2999), proxies'
 *   numbers outside the range (ProxySQL's 9001 "Max connect timeout reached") and other
 *   databases' numbers fall outside and are read as before.
 *
 * Such an error's message echoes what the caller sent, so neither it nor the message of an
 * error wrapping it that quotes it (its message or the driver's text, QUOTE_MIN_LENGTH
 * characters or more) is read for words (unread()). The contention and timeouts the server's
 * own text names keep their tags (tags()), and a wrapper quoting it gets the tags its words
 * gave in 2.4.0 from that text (quotedTags()), so the order is 2.4.0's.
 *
 * @internal
 */
final class DatabaseAnswer
{
    private const ANSWER_CLASSES = ['21', '22', '23', '42', '44', '45', 'P0'];

    /** Serialization failure and PostgreSQL's deadlock: contention, the database answered. */
    private const CONTENTION_STATES = ['40001', '40P01'];

    /**
     * MySQL server numbers whose text is not the server's answer about what it was sent, read
     * as before 2.4.1: 1105 "Unknown error", 1815 "Internal error: %s", 1317 "Query execution
     * was interrupted", 1203 (max_user_connections) and 1041 (out of resources), which Vitess,
     * TiDB and proxies send with their own text, outages included (Vitess: DEADLINE_EXCEEDED,
     * CANCELED and ABORTED as 1317, INTERNAL as 1815, RESOURCE_EXHAUSTED as 1203 or 1041); and
     * 1296, 1297, 1429 and 1430, an engine (FEDERATED, NDB) relaying another server's error;
     * and 1927, MariaDB's "Connection was killed", which MaxScale sends with its own text
     * ("Lost connection to backend server: network error (node2, Connection refused)").
     */
    private const READ_ANYWAY = [1041, 1105, 1203, 1296, 1297, 1317, 1429, 1430, 1815, 1927];

    /**
     * A proxy's or a MySQL-compatible database's own error under a number that is something
     * else on a MySQL server, by that number and the start of its text: read as before 2.4.1.
     * ProxySQL's query_timeout (MySQL 8.0 has no 1907; MariaDB's is about a generated column
     * and stays an answer); SingleStore's leaf connect error (MySQL's and MariaDB's 1735 is
     * "Unknown partition '%s' in table '%s'", which stays an answer).
     */
    private const PROXY_TEXTS = [
        1907 => 'query execution was interrupted, query_timeout exceeded',
        1735 => 'unable to connect to leaf',
    ];

    /**
     * The shortest text taken as a quote of an answer. A shorter driver text ("connection", a
     * SIGNAL's "Operation timed out") is as likely to be words of the error above it.
     */
    private const QUOTE_MIN_LENGTH = 24;

    /** MySQL's 1213 deadlock and 1205 lock wait timeout; SQL Server's 1205 deadlock victim. */
    private const DEADLOCK_NUMBERS = [1205, 1213];

    /**
     * MySQL server errors whose own text, not a value, names contention or a timeout: the start
     * of that text, the tags its words gave before 2.4.1 in the order they gave them (now given
     * by the number and that start), and whether it is a lock timeout (ServiceFailureVerdict
     * drops its ETIMEDOUT).
     *
     * @var array<int, array{string, list<string>, bool}>
     */
    private const MYSQL_TEXT_TAGS = [
        1205 => ['lock wait timeout exceeded', [FailureToken::ETIMEDOUT, FailureToken::DEADLOCK], true],
        1213 => ['deadlock found', [FailureToken::DEADLOCK], false],
        1614 => ['xa_rbdeadlock', [FailureToken::DEADLOCK], false],
        1639 => ['debug sync point wait timed out', [FailureToken::ETIMEDOUT], false],
        3058 => ['deadlock found', [FailureToken::DEADLOCK], false],
        3132 => ['deadlock found', [FailureToken::DEADLOCK], false],
        3133 => ['service lock wait timeout exceeded', [FailureToken::ETIMEDOUT, FailureToken::DEADLOCK], true],
        3699 => ['timeout exceeded in regular expression', [FailureToken::ETIMEDOUT], false],
        3781 => ['timeout exceeded for held statement', [FailureToken::ETIMEDOUT], false],
        3840 => ['redo log archiving start timed out', [FailureToken::ETIMEDOUT], false],
    ];

    /**
     * PDO's prefix: "SQLSTATE[HY000]: General error: 1366 text", "SQLSTATE[HY000] [1049] text",
     * "SQLSTATE[01002]: OCISessionBegin: ORA-01033: text". Groups: state, description, number,
     * bracketed number, text.
     */
    private const PDO_PREFIX = '~^SQLSTATE\[([0-9A-Za-z]{5})\](?:: ([^:\n]*)(?:: (\d+))?| \[(\d+)\])? ?(.*)~s';

    /** PostgreSQL's lock_timeout (55P03), as the server's text starts. */
    private const PG_LOCK_TIMEOUT = '~^(?:error:\s+)?canceling statement due to lock timeout~';

    /** Whether $t itself is a database's answer. */
    public static function is(\Throwable $t): bool
    {
        $id = self::identity($t);
        if ($id === null || self::isConnection($id)) {
            return false;
        }
        $n = $id['number'];
        if ($n !== null && !$id['oracle'] && (in_array($n, self::READ_ANYWAY, true) || self::isProxyText($n, $id['texts']))) {
            return false;
        }
        foreach ($id['states'] as $state) {
            if (in_array(substr($state, 0, 2), self::ANSWER_CLASSES, true) || in_array($state, self::CONTENTION_STATES, true)) {
                return true;
            }
        }
        return $n !== null && !$id['oracle']
            && (($n >= 1000 && $n <= 1999) || ($n >= 3000 && $n <= 5999));
    }

    /**
     * The depths, from the outermost error along getPrevious() and within $maxDepth, whose
     * message is not read for words: each database answer, and each error above one whose
     * message quotes it (the answer's message or the driver's text, in any case), as DBAL's
     * wrappers, CodeIgniter's and "Saving failed: " . $e->getMessage() do. An error above an
     * answer that does not quote it (a cache's read error thrown in a finally while the answer
     * unwinds) is read: its words are its own.
     *
     * @return array<int, true>
     */
    public static function unread(\Throwable $e, int $maxDepth): array
    {
        return self::scan($e, $maxDepth)[0];
    }

    /**
     * For each depth unread() gives that is not itself an answer (an error quoting one), the
     * tags 2.4.0 read from the words it quotes: those the server's own text names (tags()),
     * in that text's order. A wrapper quoting a 1205 gets ETIMEDOUT then DEADLOCK, as 2.4.0
     * read them from its message; a wrapper quoting a duplicate key gets nothing.
     *
     * @return array<int, list<string>>
     */
    public static function quotedTags(\Throwable $e, int $maxDepth): array
    {
        return self::scan($e, $maxDepth)[1];
    }

    /** @return array{array<int, true>, array<int, list<string>>} */
    private static function scan(\Throwable $e, int $maxDepth): array
    {
        $chain = [];
        for ($t = $e, $depth = 0; $t !== null && $depth < $maxDepth; $t = $t->getPrevious(), $depth++) {
            $chain[] = $t;
        }
        $unread = [];
        $quotedTags = [];
        /** @var list<array{string, list<string>}> $quotes each quote and the tags its words gave in 2.4.0 */
        $quotes = [];
        for ($depth = count($chain) - 1; $depth >= 0; $depth--) {
            $t = $chain[$depth];
            if (self::is($t)) {
                $unread[$depth] = true;
                $id = self::identity($t);
                $tags = self::textTags($id);
                foreach ([strtolower($t->getMessage()), ...($id['texts'] ?? [])] as $quote) {
                    $quote = trim($quote);
                    if (strlen($quote) >= self::QUOTE_MIN_LENGTH) {
                        $quotes[] = [$quote, $tags];
                    }
                }
                continue;
            }
            $message = strtolower($t->getMessage());
            foreach ($quotes as [$quote, $tags]) {
                if (str_contains($message, $quote)) {
                    $unread[$depth] = true;
                    $quotedTags[$depth] = array_values(array_unique(array_merge($quotedTags[$depth] ?? [], $tags)));
                }
            }
        }
        return [$unread, $quotedTags];
    }

    /**
     * The tags the server's own text gives (MYSQL_TEXT_TAGS), by number and the start of that text.
     *
     * @param array{states: list<string>, number: ?int, texts: list<string>, oracle: bool}|null $id
     * @return list<string>
     */
    private static function textTags(?array $id): array
    {
        $n = $id === null || $id['oracle'] ? null : $id['number'];
        if ($n !== null && isset(self::MYSQL_TEXT_TAGS[$n]) && self::startsWith($id['texts'], self::MYSQL_TEXT_TAGS[$n][0])) {
            return self::MYSQL_TEXT_TAGS[$n][1];
        }
        return [];
    }

    /** Whether $t's own SQLSTATE or number says connection: class 08 and the like, or a MySQL connection number. */
    public static function isConnectionError(\Throwable $t): bool
    {
        $id = self::identity($t);
        return $id !== null && self::isConnection($id);
    }

    /**
     * DEADLOCK and ETIMEDOUT from $t's own SQLSTATE, number and the start of the server's text,
     * in the order 2.4.0 gave them: a PDOException's errorInfo SQLSTATE or number first, then
     * the server's words.
     *
     * @return list<string>
     */
    public static function tags(\Throwable $t): array
    {
        $id = self::identity($t);
        if ($id === null) {
            return [];
        }
        $n = $id['oracle'] ? null : $id['number'];
        $deadlock = array_intersect($id['states'], self::CONTENTION_STATES) !== [] || in_array($n, self::DEADLOCK_NUMBERS, true);
        $tags = [];
        if ($deadlock && self::pdoFieldsSayDeadlock($t)) {
            $tags[] = FailureToken::DEADLOCK;
        }
        $tags = array_merge($tags, self::textTags($id));
        if ($deadlock) {
            $tags[] = FailureToken::DEADLOCK;
        }
        return array_values(array_unique($tags));
    }

    /**
     * Whether an error on $e's chain is a lock timeout: MySQL's 1205 or 3133 by number and the
     * start of the server's text, PostgreSQL's 55P03 "canceling statement due to lock timeout",
     * Doctrine DBAL's LockWaitTimeoutException, or an error with no database fields whose
     * message starts with those words (a driver's message rethrown).
     */
    public static function isLockTimeout(\Throwable $e, int $maxDepth): bool
    {
        for ($t = $e, $depth = 0; $t !== null && $depth < $maxDepth; $t = $t->getPrevious(), $depth++) {
            if (
                class_exists(\Doctrine\DBAL\Exception\LockWaitTimeoutException::class)
                && $t instanceof \Doctrine\DBAL\Exception\LockWaitTimeoutException
            ) {
                return true;
            }
            $id = self::identity($t);
            if ($id === null) {
                if ($t instanceof \LogicException || $t instanceof \Error) {
                    continue;
                }
                $message = strtolower(ltrim($t->getMessage()));
                if (str_starts_with($message, 'lock wait timeout exceeded') || preg_match(self::PG_LOCK_TIMEOUT, $message)) {
                    return true;
                }
                continue;
            }
            $n = $id['number'];
            if ($n !== null && !$id['oracle'] && (self::MYSQL_TEXT_TAGS[$n][2] ?? false) && self::startsWith($id['texts'], self::MYSQL_TEXT_TAGS[$n][0])) {
                return true;
            }
            foreach ($id['texts'] as $text) {
                if (preg_match(self::PG_LOCK_TIMEOUT, $text)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<string> $texts */
    private static function startsWith(array $texts, string $start): bool
    {
        foreach ($texts as $text) {
            if (str_starts_with($text, $start)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $texts */
    private static function isProxyText(int $n, array $texts): bool
    {
        return isset(self::PROXY_TEXTS[$n]) && self::startsWith($texts, self::PROXY_TEXTS[$n]);
    }

    /**
     * Whether a PDOException's own fields name a deadlock the way 2.4.0 read them first: its
     * SQLSTATE (errorInfo[0], else a string code) 40001 or 40P01, or errorInfo[1] 1205 or 1213.
     */
    private static function pdoFieldsSayDeadlock(\Throwable $t): bool
    {
        if (!$t instanceof \PDOException) {
            return false;
        }
        $info = is_array($t->errorInfo) ? $t->errorInfo : [];
        $code = $t->getCode();
        $state = isset($info[0]) && is_string($info[0]) && $info[0] !== '' ? $info[0] : (is_string($code) ? $code : '');
        if (in_array(strtoupper($state), self::CONTENTION_STATES, true)) {
            return true;
        }
        $number = $info[1] ?? null;
        return (is_int($number) || (is_string($number) && ctype_digit($number))) && in_array((int) $number, self::DEADLOCK_NUMBERS, true);
    }

    /** @param array{states: list<string>, number: ?int, texts: list<string>, oracle: bool} $id */
    private static function isConnection(array $id): bool
    {
        foreach ($id['states'] as $state) {
            if (preg_match(UnavailableEvidence::SQLSTATE_PATTERN, $state)) {
                return true;
            }
        }
        return $id['number'] !== null && UnavailableEvidence::isConnectionNumber($id['number'], $id['texts']);
    }

    /**
     * What $t carries as a database error, or null when nothing. States are upper-cased; texts
     * are the driver's own messages, lower-cased: errorInfo[2] or a mysqli_sql_exception's
     * message, then what follows PDO's prefix in the message.
     *
     * @return array{states: list<string>, number: ?int, texts: list<string>, oracle: bool}|null
     */
    private static function identity(\Throwable $t): ?array
    {
        $states = [];
        $number = null;
        $texts = [];
        $description = '';
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
                    $texts[] = $info[2];
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
            $texts[] = $t->getMessage();
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
        // A LogicException or an \Error is a bug or a validation error: its message may start
        // with the value it rejects ("SQLSTATE[08006] is not a valid coupon code").
        $readMessage = !($t instanceof \LogicException || $t instanceof \Error);
        if ($readMessage && preg_match(self::PDO_PREFIX, $t->getMessage(), $m)) {
            $states[] = $m[1];
            $description = strtolower(ltrim($m[2] ?? ''));
            $n = ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '');
            if ($n !== '') {
                $number ??= (int) $n;
            }
            $texts[] = $m[5] ?? '';
        }
        if ($states === [] && $number === null) {
            return null;
        }
        $texts = array_map(static fn (string $text): string => strtolower(ltrim($text)), $texts);
        $oracle = str_starts_with($description, 'oci');
        foreach ($texts as $text) {
            $oracle = $oracle || str_starts_with($text, 'oci');
        }
        return [
            'states' => array_values(array_unique(array_map('strtoupper', $states))),
            'number' => $number,
            'texts' => array_values($texts),
            'oracle' => $oracle,
        ];
    }
}
