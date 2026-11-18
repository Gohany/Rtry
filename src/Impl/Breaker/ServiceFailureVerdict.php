<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Breaker;

use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Rtry\Contracts\BreakerVerdictInterface;
use Gohany\Rtry\Impl\FailureToken;

/**
 * The breaker verdict by the service's own answer, whatever the policy retries:
 *
 * - a status of 408, 429 or 500 to 599 is a failure;
 * - so is a tag in $unavailableTags (by default the transport tags ETIMEDOUT, ECONNRESET,
 *   ECONNREFUSED and NETWORK_ERROR, plus RATE_LIMITED, which the default classifier sets only
 *   on a 403, 408, 429 or 5xx carrying a server's wait), matched without regard to case;
 * - anything else is a success: a 404 or a 422 (the service answered), a DEADLOCK (the
 *   database answered: contention, not an outage), an error with no status and no such tag.
 *
 * A status alone never needs a tag, and a tag counts whatever the status: the default
 * classifier gives a response's 4xx no transport tags, while an exception code that is not
 * HTTP (SMTP's 421 "Connection timed out") keeps its ETIMEDOUT and counts.
 *
 * Add the tags your own classifier rules set for "never answered" (an SDK's connection error):
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

    /** @var array<string, true> upper-cased */
    private readonly array $unavailableTags;

    /** @param list<string> $unavailableTags tags that mean the service did not answer */
    public function __construct(array $unavailableTags = self::UNAVAILABLE_TAGS)
    {
        $set = [];
        foreach ($unavailableTags as $tag) {
            $set[strtoupper($tag)] = true;
        }
        $this->unavailableTags = $set;
    }

    public function isServiceFailure(AttemptOutcomeInterface $outcome): bool
    {
        if ($outcome->isSuccess()) {
            return false;
        }
        $status = $outcome->statusCode();
        if ($status === 408 || $status === 429 || ($status !== null && $status >= 500 && $status <= 599)) {
            return true;
        }
        foreach ($outcome->tags() as $tag) {
            if (isset($this->unavailableTags[strtoupper($tag)])) {
                return true;
            }
        }
        return false;
    }
}
