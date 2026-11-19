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
 * (PDOException, mysqli_sql_exception), as its connection error number. The message of a
 * LogicException or an \Error (a bug or a validation error) is never read; its previous
 * errors still are. Nor is the message of a database error whose SQLSTATE says the database
 * answered (DatabaseAnswer): "Duplicate entry 'broken pipe'" echoes what the caller sent.
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

    private const DRIVER_TYPES = ['PDOException', 'mysqli_sql_exception'];

    /**
     * MySQL / MariaDB: 1040 too many connections, 1053 server shutdown in progress, 2002 and
     * 2003 cannot connect, 2005 unknown host, 2006 server has gone away, 2013 and 2055 lost
     * connection.
     */
    private const MYSQL_CODES = [1040, 1053, 2002, 2003, 2005, 2006, 2013, 2055];

    /**
     * SQLSTATE class 08 (connection exception), 57P01-57P03 (PostgreSQL shutting down or not
     * yet accepting connections), 53300 (too many connections).
     */
    private const SQLSTATE_PATTERN = '~^(08[0-9A-Z]{3}|57P0[123]|53300)$~';

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
        // TLS
        'ssl certificate problem', 'certificate has expired', 'certificate verify failed',
        'ssl_connect', 'ssl handshake', 'tls handshake',
    ];

    public static function in(\Throwable $e): bool
    {
        for ($t = $e, $depth = 0; $t !== null && $depth < self::MAX_DEPTH; $t = $t->getPrevious(), $depth++) {
            if (self::one($t)) {
                return true;
            }
        }
        return false;
    }

    private static function one(\Throwable $t): bool
    {
        foreach (self::TYPES as $type) {
            if ($t instanceof $type) {
                return true;
            }
        }
        foreach (self::DRIVER_TYPES as $type) {
            if ($t instanceof $type && self::isDriverConnectionError($t)) {
                return true;
            }
        }
        if ($t instanceof \LogicException || $t instanceof \Error || DatabaseAnswer::is($t)) {
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

    private static function isDriverConnectionError(\Throwable $t): bool
    {
        $info = $t instanceof \PDOException ? $t->errorInfo : null;
        if (is_array($info)) {
            if (isset($info[0]) && is_string($info[0]) && preg_match(self::SQLSTATE_PATTERN, strtoupper($info[0]))) {
                return true;
            }
            if (isset($info[1]) && is_numeric($info[1]) && in_array((int) $info[1], self::MYSQL_CODES, true)) {
                return true;
            }
        }
        $code = $t->getCode();
        if (is_int($code)) {
            return in_array($code, self::MYSQL_CODES, true);
        }
        return is_string($code) && preg_match(self::SQLSTATE_PATTERN, strtoupper($code)) === 1;
    }
}
