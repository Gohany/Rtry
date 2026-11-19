<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Contracts\RuleBasedClassifierInterface;
use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Impl\Rules\MethodStatusRule;
use Gohany\Rtry\Impl\Rules\RateLimitBackoffRule;
use Gohany\Rtry\Impl\Rules\ResponseStatusRule;

/**
 * Turns a thrown error into a status and tags (README, "What retries"):
 *
 * - A status read from an HTTP response the error carries, at any depth of its getPrevious()
 *   chain (ErrorResponseLocator), decides. A retryable one (408, 429, 5xx) also gets the
 *   error's transport tags; any other (a 404, a 422, a 302, a 999) gets none, so the words in
 *   a 404's URL never make it retryable.
 * - Otherwise the exception code, when it is 100 to 599, is the status for on= matching, as in
 *   2.x. It never silences tags: SMTP's 421 and a driver's 409 are codes from that range.
 * - Tags come only from transport and deadlock evidence: transport types (PSR-18
 *   NetworkExceptionInterface, Symfony HttpClient TransportExceptionInterface), a cURL error
 *   number, deadlock SQLSTATEs and vendor codes, errno names (ECONNREFUSED, ETIMEDOUT,
 *   ECONNRESET), and specific phrases ("connection refused", "timed out", "deadlock found",
 *   driver timeouts such as PostgreSQL's "canceling statement due to statement timeout"),
 *   never a bare word such as "timeout" or "refused". A
 *   LogicException or an \Error (TypeError, ValueError) is a bug or a validation error: its
 *   message is never read for tags. Nor is the message of a database error whose SQLSTATE says
 *   the database answered about the values it was sent (DatabaseAnswer: class 21, 22, 23, 42, 44,
 *   45 or P0), since such a message echoes them: "Duplicate entry 'timed out'" runs once.
 * - Tags from rules you add are kept whatever the status.
 */
final class RuleBasedFailureClassifier implements FailureClassifierInterface, RuleBasedClassifierInterface
{
    private const MAX_DEPTH = 16;

    /** Phrases that name a timeout. Not "timeout" alone: "timeout must be positive" is not one. */
    private const TIMEOUT_PHRASES = [
        'timed out', 'timeout was reached', 'timeout reached', 'timeout exceeded', 'timeout expired',
        'timeout waiting', 'connection timeout', 'read timeout', 'request timeout', 'gateway timeout',
        'socket timeout',
        'due to statement timeout',  // PostgreSQL 57014, statement_timeout
        'due to lock timeout',       // PostgreSQL 55P03, lock_timeout
        'socket error or timeout',   // MongoDB
        'timeout occurred',          // Oracle ORA-12170 "TNS:Connect timeout occurred", libmemcached
        'i/o timeout',               // Go net ("dial tcp ...: i/o timeout")
    ];
    private const RESET_PHRASES = ['connection reset', 'connection was reset'];
    /** Not "refused" alone: "payment refused" and "consent refused" are not transport errors. */
    private const REFUSED_PHRASES = ['connection refused', 'actively refused'];
    private const NETWORK_PHRASES = ['network error', 'network timeout', 'network is unreachable', 'host unreachable', 'no route to host'];
    private const DEADLOCK_PHRASES = [
        'deadlock',                    // "deadlock detected/found/victim"
        'lock wait timeout exceeded',  // MySQL InnoDB
        'due to lock timeout',         // PostgreSQL 55P03
        'database is locked',          // SQLite
        'could not serialize access',  // PostgreSQL
        'serialization failure',       // SQL standard / PostgreSQL
    ];
    /** errno names as Node.js, libuv and many drivers print them ("connect ECONNREFUSED 127.0.0.1:6379"). */
    private const ERRNO_NAMES = [
        'etimedout' => FailureToken::ETIMEDOUT,
        'econnrefused' => FailureToken::ECONNREFUSED,
        'econnreset' => FailureToken::ECONNRESET,
    ];
    /** cURL error numbers ("cURL error 28: ...") that mean the request never got a response. */
    private const CURL_ERRORS = [
        7 => FailureToken::NETWORK_ERROR,   // could not connect
        28 => FailureToken::ETIMEDOUT,      // operation timed out
        35 => FailureToken::NETWORK_ERROR,  // TLS handshake failed
        52 => FailureToken::NETWORK_ERROR,  // empty reply
        55 => FailureToken::NETWORK_ERROR,  // send failure
        56 => FailureToken::NETWORK_ERROR,  // receive failure
    ];
    private const TRANSPORT_TYPES = [
        'Psr\\Http\\Client\\NetworkExceptionInterface',
        'Symfony\\Contracts\\HttpClient\\Exception\\TransportExceptionInterface',
    ];

    /** @var array<int,RuleInterface> */
    private array $rules = [];


    /**
     * @param array<int,RuleInterface> $rules
     */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    /**
     * What every policy gets unless a classifier is injected: the status of the HTTP response
     * on the error, and Retry-After / RateLimit-Reset from its headers, found anywhere on the
     * error by an ErrorResponseLocator. $reader is tried first, for a client that keeps its
     * response somewhere the locator does not look; the default reading still follows.
     * MethodStatusRule(['getResponse']) stays first, as in 2.x.
     */
    public static function default(?ErrorResponseReaderInterface $reader = null): self
    {
        $locator = ErrorResponseLocator::wrap($reader);
        return new self([
            new MethodStatusRule(['getResponse']),
            new ResponseStatusRule($locator),
            new RateLimitBackoffRule($locator),
        ]);
    }

    public function classify(\Throwable $e): FailureMetadataInterface
    {
        $metas = $this->applyRules($e);

        $status = null;
        $tags = [];
        $ctxPatch = [];
        $minNextDelayMs = null;
        $notBeforeUnixMs = null;
        $headers = [];
        $fromResponse = false;
        $responseStatus = null;

        if ($metas !== null) {
            foreach ($metas as $m) {
                if ($m instanceof FailureMetadata && $m->isFromResponse()) {
                    $fromResponse = true;
                    $responseStatus = $m->getStatusCode() ?? $responseStatus;
                }

                // Most recent non-null status code wins
                $s = $m->getStatusCode();
                if ($s !== null) {
                    $status = $s;
                }

                // Collect all tags (dedupe later)
                $tags = array_merge($tags, $m->getTags());

                // Later patches overwrite earlier ones
                $ctxPatch = array_replace($ctxPatch, $m->getContextPatch());

                // Respect the strictest constraints
                $min = $m->getMinNextDelayMs();
                if ($min !== null) {
                    $minNextDelayMs = $minNextDelayMs === null ? $min : max($minNextDelayMs, $min);
                }

                $nb = $m->getNotBeforeUnixMs();
                if ($nb !== null) {
                    $notBeforeUnixMs = $notBeforeUnixMs === null ? $nb : max($notBeforeUnixMs, $nb);
                }

                // Merge headers by name, append values, dedupe
                $headers = $this->mergeHeaders($headers, $m->getHeaders());
            }
        }

        if ($fromResponse) {
            // A status from a response decides. Only a retryable one also gets transport tags
            // (2.x callers match a timed-out 504 with on=ETIMEDOUT).
            if ($responseStatus !== null && self::isRetryableStatus($responseStatus)) {
                $tags = array_merge($tags, $this->collectTags($e));
            }
        } else {
            // No response: the code is the status for on=, as in 2.x, but never proof of HTTP.
            $status ??= $this->extractStatusCode($e);
            $tags = array_merge($tags, $this->collectTags($e));
        }
        $tags = array_values(array_unique($tags));

        // fromResponse says the status was read from the response: not when a later rule's
        // status replaced the response's.
        return new FailureMetadata($status, $tags, $ctxPatch, $minNextDelayMs, $notBeforeUnixMs, $headers, $fromResponse && $status === $responseStatus);
    }

    private static function isRetryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * @return array<int,FailureMetadataInterface>|null
     */
    private function applyRules(\Throwable $e): ?array
    {
        $out = [];
        foreach ($this->rules as $rule) {
            $result = $rule->apply($e);

            if ($result instanceof FailureMetadataInterface) {
                $out[] = $result;
                continue;
            }

            if (is_array($result)) {
                foreach ($result as $item) {
                    if ($item instanceof FailureMetadataInterface) {
                        $out[] = $item;
                    }
                }
            }
        }

        return !empty($out) ? $out : null;
    }

    public function addRule(RuleInterface $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    public function hasRuleOfType(string $fqcn): bool
    {
        foreach ($this->rules as $r) {
            if ($r instanceof $fqcn) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, array<int,string>> $base
     * @param array<string, array<int,string>> $add
     * @return array<string, array<int,string>>
     */
    private function mergeHeaders(array $base, array $add): array
    {
        foreach ($add as $name => $values) {
            $key = strtolower((string) $name);
            if (!isset($base[$key])) {
                $base[$key] = [];
            }
            foreach ((array) $values as $v) {
                if (is_scalar($v) || $v instanceof \Stringable) {
                    $base[$key][] = (string) $v;
                }
            }
            // Dedupe while preserving order
            $base[$key] = array_values(array_unique($base[$key]));
        }
        return $base;
    }

    private function extractStatusCode(\Throwable $e): ?int
    {
        $code = (int) $e->getCode();

        if ($code >= 100 && $code <= 599) {
            return $code;
        }

        return null;
    }

    /**
     * Transport and deadlock tags from the error and its getPrevious() chain.
     *
     * @return array<int,string>
     */
    private function collectTags(\Throwable $e): array
    {
        $tags = [];
        for ($t = $e, $depth = 0; $t !== null && $depth < self::MAX_DEPTH; $t = $t->getPrevious(), $depth++) {
            foreach (self::TRANSPORT_TYPES as $type) {
                if ($t instanceof $type) {
                    $tags[] = FailureToken::NETWORK_ERROR;
                }
            }
            if ($this->isDeadlockType($t)) {
                $tags[] = FailureToken::DEADLOCK;
            }

            // A LogicException or an \Error is a bug or a validation error, whatever it says; a
            // database error whose SQLSTATE says it answered (a duplicate key, bad data) echoes
            // the values it was sent, so its words are not the caller's to choose tags with.
            if ($t instanceof \LogicException || $t instanceof \Error || DatabaseAnswer::is($t)) {
                continue;
            }
            $message = strtolower((string) $t->getMessage());
            if ($this->containsAny($message, self::TIMEOUT_PHRASES)) {
                $tags[] = FailureToken::ETIMEDOUT;
            }
            if ($this->containsAny($message, self::RESET_PHRASES)) {
                $tags[] = FailureToken::ECONNRESET;
            }
            if ($this->containsAny($message, self::REFUSED_PHRASES)) {
                $tags[] = FailureToken::ECONNREFUSED;
            }
            if ($this->containsAny($message, self::NETWORK_PHRASES)) {
                $tags[] = FailureToken::NETWORK_ERROR;
            }
            foreach (self::ERRNO_NAMES as $name => $token) {
                if (preg_match('~\b' . $name . '\b~', $message)) {
                    $tags[] = $token;
                }
            }
            if (preg_match('~\bcurl error (\d+)~', $message, $m) && isset(self::CURL_ERRORS[(int) $m[1]])) {
                $tags[] = self::CURL_ERRORS[(int) $m[1]];
            }
            if ($this->containsAny($message, self::DEADLOCK_PHRASES)) {
                $tags[] = FailureToken::DEADLOCK;
            }
        }

        return array_values(array_unique($tags));
    }

    private function isDeadlockType(\Throwable $t): bool
    {
        if (
            class_exists(\Doctrine\DBAL\Exception\DeadlockException::class)
            && $t instanceof \Doctrine\DBAL\Exception\DeadlockException
        ) {
            return true;
        }

        // PDOException: inspect SQLSTATE and vendor codes
        if ($t instanceof \PDOException) {
            $sqlState = $this->extractSqlState($t);
            if ($sqlState !== null && $this->isDeadlockSqlState($sqlState)) {
                return true;
            }

            $vendorCode = $this->extractVendorCode($t);
            if ($vendorCode !== null && $this->isDeadlockVendorCode($vendorCode)) {
                return true;
            }
        }

        return false;
    }

    private function extractSqlState(\PDOException $e): ?string
    {
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0]) && $errorInfo[0] !== '') {
            return strtoupper($errorInfo[0]);
        }

        $code = $e->getCode();
        if (is_string($code) && $code !== '') {
            return strtoupper($code);
        }

        return null;
    }

    private function isDeadlockSqlState(string $sqlState): bool
    {
        // Common SQLSTATEs for deadlocks/serialization failures
        // 40001: Serialization failure (PostgreSQL, SQL standard)
        // 40P01: Deadlock detected (PostgreSQL)
        return in_array($sqlState, ['40001', '40P01'], true);
    }

    private function extractVendorCode(\PDOException $e): ?int
    {
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[1])) {
            $vendor = $errorInfo[1];

            if (is_int($vendor)) {
                return $vendor;
            }

            if (is_string($vendor) && ctype_digit($vendor)) {
                return (int) $vendor;
            }
        }

        return null;
    }

    private function isDeadlockVendorCode(int $vendorCode): bool
    {
        // MySQL / MariaDB / MSSQL common vendor codes:
        // 1213: ER_LOCK_DEADLOCK (MySQL)
        // 1205: Lock wait timeout exceeded / deadlock victim (MySQL/MSSQL)
        return in_array($vendorCode, [1213, 1205], true);
    }

    /**
     * @param array<int,string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}