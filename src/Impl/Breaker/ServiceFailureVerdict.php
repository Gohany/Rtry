<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Breaker;

use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Rtry\Contracts\BreakerVerdictInterface;
use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Impl\DatabaseAnswer;
use Gohany\Rtry\Impl\ErrorResponseLocator;
use Gohany\Rtry\Impl\FailureToken;
use Gohany\Rtry\Impl\RtryAttemptOutcome;

/**
 * The breaker verdict by the service's own answer. A failed attempt counts when:
 *
 * - the policy retries it ($retryable), unless constructed with countRetryable: false, so by
 *   default this verdict only ever adds failures to what the breaker hears without one;
 * - a status read from an HTTP response the error carries, at any depth of its getPrevious()
 *   chain, is 408, 429 or 500 to 599. An exception code is never read as a status: a
 *   validation error with code 500, SMTP's 550 or AMQP's 530 is not the service failing;
 * - it carries a tag in $unavailableTags (by default the transport tags ETIMEDOUT,
 *   ECONNRESET, ECONNREFUSED and NETWORK_ERROR, plus RATE_LIMITED, which the default classifier
 *   sets only on a response's 403, 408, 429 or 5xx carrying a server's wait), matched without
 *   regard to case;
 * - no response was read and the error shows the service could not be reached or could not take
 *   the call (UnavailableEvidence): a transport exception type, a DNS failure, a refused or
 *   lost connection, "MySQL server has gone away", too many connections, a phpredis read error,
 *   a broken pipe, a certificate failure, by phrase, cURL error number, errno name, SQLSTATE or
 *   a database driver's connection error number.
 *
 * Anything else is a success: a 404 or a 422 (the service answered), a DEADLOCK or a lock wait
 * timeout (the database answered: contention, not an outage; the ETIMEDOUT a lock timeout also
 * gets does not count when the error is one: MySQL 1205 or PostgreSQL 55P03), a database's
 * answer about the values it was sent (DatabaseAnswer: a duplicate key, MySQL's "Incorrect
 * integer value": its message echoes them and is not read), a validation error, an error with
 * nothing to go on.
 *
 * Where the status came from: an outcome whose statusFromResponse() is true read it from a
 * response, and it is trusted. For any other outcome (false says only that the status is not the
 * response's: an exception code, a rule's constant) this verdict looks for a response on the
 * error itself, with $reader first when given, and ignores the outcome's status when there is
 * none.
 *
 * With countRetryable: false the policy's answer is ignored and only the service's counts, so a
 * 404 retried under on=default,404 keeps the circuit closed; but then an outage the policy
 * retries and none of the above names (your own tag for it) becomes a success, and a half-open
 * probe that fails that way closes the circuit. Add such tags:
 * new ServiceFailureVerdict([...ServiceFailureVerdict::UNAVAILABLE_TAGS, 'SDK_TRANSPORT']).
 */
final class ServiceFailureVerdict implements BreakerVerdictInterface
{
    /** @var list<string> */
    public const UNAVAILABLE_TAGS = [
        FailureToken::ETIMEDOUT,
        FailureToken::ECONNRESET,
        FailureToken::ECONNREFUSED,
        FailureToken::NETWORK_ERROR,
        FailureToken::RATE_LIMITED,
    ];

    private const MAX_DEPTH = 16;

    /** @var array<string, true> upper-cased */
    private readonly array $unavailableTags;
    private readonly ErrorResponseLocator $locator;

    /**
     * @param list<string|int> $unavailableTags tags that mean the service did not answer
     * @param bool $countRetryable count every failure the policy retries, as without a verdict
     * @param ErrorResponseReaderInterface|null $reader tried first when looking for a response on
     *        an error that Rtry's classifier did not classify
     */
    public function __construct(
        array $unavailableTags = self::UNAVAILABLE_TAGS,
        private readonly bool $countRetryable = true,
        ?ErrorResponseReaderInterface $reader = null,
    ) {
        $set = [];
        foreach ($unavailableTags as $tag) {
            $set[strtoupper((string) $tag)] = true;
        }
        $this->unavailableTags = $set;
        $this->locator = ErrorResponseLocator::wrap($reader);
    }

    public function isServiceFailure(AttemptOutcomeInterface $outcome, bool $retryable): bool
    {
        if ($outcome->isSuccess()) {
            return false;
        }
        if ($retryable && $this->countRetryable) {
            return true;
        }
        $status = $this->responseStatus($outcome);
        if ($status === 408 || $status === 429 || ($status !== null && $status >= 500 && $status <= 599)) {
            return true;
        }
        $tags = [];
        foreach ($outcome->tags() as $tag) {
            $tags[strtoupper((string) $tag)] = true;
        }
        $error = $outcome->error();
        if (isset($tags[FailureToken::DEADLOCK]) && $error !== null && DatabaseAnswer::isLockTimeout($error, self::MAX_DEPTH)) {
            // A lock wait timeout: the database answered, whatever its words say about time.
            // Only then: a DEADLOCK from the bare word "deadlock" beside a real timeout
            // ("Read timed out ... for sku 'deadlock'") leaves the timeout counting.
            unset($tags[FailureToken::ETIMEDOUT]);
        }
        foreach ($tags as $tag => $_) {
            if (isset($this->unavailableTags[$tag])) {
                return true;
            }
        }
        return $status === null && $error !== null && UnavailableEvidence::in($error);
    }

    /**
     * The status of the response the failure carries: null when there is none, 0 when there is
     * one without a status from 100 to 599. Only statusFromResponse() true is taken on trust;
     * false is also FailureMetadata's default, so it never means "no response".
     */
    private function responseStatus(AttemptOutcomeInterface $outcome): ?int
    {
        if ($outcome instanceof RtryAttemptOutcome && $outcome->statusFromResponse() === true) {
            return $outcome->statusCode() ?? 0;
        }
        $error = $outcome->error();
        return $error === null ? null : $this->locator->read($error)?->statusCode;
    }
}
