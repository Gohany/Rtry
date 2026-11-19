<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Breaker;

use Gohany\Rtry\Impl\DatabaseAnswer;

/**
 * Whether a thrown error shows that a downstream service could not be reached or could not
 * take the call, from its type, a driver's connection error number, a cURL error number, an
 * errno name or a specific phrase, anywhere in its getPrevious() chain. ServiceFailureVerdict
 * asks it when no response was read, so outages the classifier leaves untagged (DNS failures,
 * lost database connections, phpredis read errors, broken pipes, certificate failures) count.
 *
 * Never from an exception code alone: a code counts only on a database driver's own exception
 * (PDOException, mysqli_sql_exception), as its connection error number; a SQLSTATE counts from
 * a database error's own fields (errorInfo, a string code, getSqlState()) or a message. The
 * message of a LogicException or an \Error (a bug or a validation error) is never read, not
 * even for a SQLSTATE at its start; its previous errors still are. Nor is the message of a
 * database's answer (DatabaseAnswer: a duplicate key, a value MySQL could not take) or of an
 * error wrapping one that quotes it: "Duplicate entry 'broken pipe'" and "Incorrect integer
 * value: 'broken pipe'" echo what the caller sent. An error above an answer that does not quote
 * it is read. Evidence from types, SQLSTATEs and driver numbers counts at any depth, answer or
 * not.
 *
 * @internal
 */
final class UnavailableEvidence
{
    private const MAX_DEPTH = 16;

    /** Types that mean the call never got an answer. Missing classes simply never match. */
    private const TYPES = [
        'Psr\\Http\\Client\\NetworkExceptionInterface',
        'Symfony\\Contracts\\HttpClient\\Exception\\TransportExceptionInterface',
        'GuzzleHttp\\Exception\\ConnectException',
        'Doctrine\\DBAL\\Exception\\ConnectionException',
        'Doctrine\\DBAL\\Exception\\ConnectionLost',
        'Predis\\Connection\\ConnectionException',
    ];

    /**
     * MySQL / MariaDB: 1040 too many connections, 1053 server shutdown in progress, 2002 and
     * 2003 cannot connect, 2005 unknown host, 2006 server has gone away, 2013 and 2055 lost
     * connection, 4031 disconnected for inactivity (what PHP 8.5's mysqlnd reports where 8.2's
     * says 2006).
     */
    public const MYSQL_CODES = [1040, 1053, 2002, 2003, 2005, 2006, 2013, 2055, 4031];

    /**
     * SQLSTATE class 08 (connection exception), 57P01-57P03 (PostgreSQL shutting down or not
     * yet accepting connections), 53300 (too many connections).
     */
    public const SQLSTATE_PATTERN = '~^(08[0-9A-Z]{3}|57P0[123]|53300)$~';

    /** cURL errors that mean no answer: proxy and host DNS, connect, timeout, TLS, empty reply, send, receive, certificate. */
    private const CURL_ERRORS = [5, 6, 7, 28, 35, 52, 55, 56, 60];

    private const ERRNO_NAMES = ['econnrefused', 'etimedout', 'econnreset', 'econnaborted', 'ehostunreach', 'enetunreach', 'epipe', 'enotfound', 'eai_again'];

    private const PHRASES = [
        // DNS
        'could not resolve host', 'could not resolve proxy', "couldn't resolve host", 'getaddrinfo',
        'name or service not known', 'temporary failure in name resolution', 'nodename nor servname',
        'no address associated with hostname',
        // connecting
        'connection refused', 'actively refused', 'connection reset', 'connection was reset',
        'connection aborted', 'connection timed out', 'connect timed out', 'network is unreachable',
        'host unreachable', 'no route to host', 'host is down', "can't connect to", 'could not connect to',
        'failed to connect to', 'unable to connect to', 'broken pipe',
        // databases and caches
        'server has gone away', 'lost connection to', 'too many connections', 'too many clients',
        'server closed the connection', 'terminating connection due to administrator command',
        'the database system is shutting down', 'the database system is starting up',
        'read error on connection', 'redis server went away', 'socket error on read',
        'no connection to the server', // pdo_pgsql, the query after the backend was ended
        // TLS
        'ssl certificate problem', 'certificate has expired', 'certificate verify failed',
        'ssl_connect', 'ssl handshake', 'tls handshake',
    ];

    public static function in(\Throwable $e): bool
    {
        $unread = DatabaseAnswer::unread($e, self::MAX_DEPTH);
        for ($t = $e, $depth = 0; $t !== null && $depth < self::MAX_DEPTH; $t = $t->getPrevious(), $depth++) {
            if (self::one($t, !isset($unread[$depth]))) {
                return true;
            }
        }
        return false;
    }

    /** @param bool $readWords false for a database's answer and the errors that quote it */
    private static function one(\Throwable $t, bool $readWords): bool
    {
        foreach (self::TYPES as $type) {
            if ($t instanceof $type) {
                return true;
            }
        }
        if (DatabaseAnswer::isConnectionError($t)) {
            return true;
        }
        if (!$readWords || $t instanceof \LogicException || $t instanceof \Error) {
            return false;
        }

        $message = strtolower($t->getMessage());
        if ($message === '') {
            return false;
        }
        foreach (self::PHRASES as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }
        foreach (self::ERRNO_NAMES as $name) {
            if (preg_match('~\b' . $name . '\b~', $message)) {
                return true;
            }
        }
        if (preg_match('~\bcurl error (\d+)~', $message, $m) && in_array((int) $m[1], self::CURL_ERRORS, true)) {
            return true;
        }
        // PDO's own formats: "SQLSTATE[08006] ...", "SQLSTATE[HY000] [2002] ...",
        // "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away". The code ends at a
        // digit boundary: PDO_OCI's "General error: 20060 ... ORA-20060" is not MySQL's 2006.
        if (preg_match('~sqlstate\[([0-9a-z]{5})\](?::\s*general error:\s*|\s*\[)?(\d{4}(?!\d))?~', $message, $m)) {
            if (preg_match(self::SQLSTATE_PATTERN, strtoupper($m[1]))) {
                return true;
            }
            if (isset($m[2]) && $m[2] !== '' && in_array((int) $m[2], self::MYSQL_CODES, true)) {
                return true;
            }
        }
        return false;
    }
}
