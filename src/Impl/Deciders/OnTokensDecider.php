<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Deciders;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Rtry\Impl\FailureToken;

/**
 * SPEC 6.3: a failed outcome is retryable when any token matches it. A three-digit token
 * matches that status, a family (5xx) any status in it, any other token an equal tag
 * (case-insensitive). "default" expands to FailureToken::defaults().
 */
final class OnTokensDecider implements RetryDeciderInterface
{
    /** @var list<int> */
    private readonly array $statuses;
    /** @var list<int> status families, e.g. 5 for 5xx */
    private readonly array $families;
    /** @var list<string> upper-cased */
    private readonly array $tags;

    /** @param list<int|string> $tokens */
    public function __construct(array $tokens = [FailureToken::DEFAULT])
    {
        $statuses = $families = $tags = [];
        foreach (self::expand($tokens) as $token) {
            if (preg_match('~^[1-5][0-9]{2}$~', $token)) {
                $statuses[] = (int) $token;
            } elseif (preg_match('~^([1-5])XX$~', $token, $m)) {
                $families[] = (int) $m[1];
            } else {
                $tags[] = $token;
            }
        }
        $this->statuses = array_values(array_unique($statuses));
        $this->families = array_values(array_unique($families));
        $this->tags = array_values(array_unique($tags));
    }

    /**
     * @param list<int|string> $tokens
     * @return list<string> upper-cased, "default" expanded
     */
    private static function expand(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $token = strtoupper(trim((string) $token));
            if ($token === FailureToken::DEFAULT) {
                array_push($out, ...FailureToken::defaults());
            } elseif ($token !== '') {
                $out[] = $token;
            }
        }
        return $out;
    }

    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        if ($outcome->isSuccess()) {
            return false;
        }

        $status = $outcome->statusCode();
        if ($status !== null) {
            if (in_array($status, $this->statuses, true) || in_array(intdiv($status, 100), $this->families, true)) {
                return true;
            }
        }

        foreach ($outcome->tags() as $tag) {
            if (in_array(strtoupper((string) $tag), $this->tags, true)) {
                return true;
            }
        }

        return false;
    }
}
