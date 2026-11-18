<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Impl\Deciders\OnTokensDecider;

/**
 * on=<token>[,<token>...]: the retryable statuses, families and tags (SPEC 6.3).
 */
final class On
{
    public const KEY = 'on';

    /** @param list<string> $tokens as written */
    private function __construct(private readonly array $tokens)
    {
    }

    /** Parses the value of on=. */
    public static function make(string $value): self
    {
        $tokens = array_map('trim', explode(',', $value));
        foreach ($tokens as $token) {
            if ($token === '' || !preg_match('~^[A-Za-z0-9_.:-]+$~', $token)) {
                throw new \InvalidArgumentException('Invalid on token "' . $token . '" in: ' . $value);
            }
        }
        return new self($tokens);
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function decider(): OnTokensDecider
    {
        return new OnTokensDecider($this->tokens);
    }

    /** Canonical value of on= (SPEC 6.4): tokens in input order. */
    public function canonical(): string
    {
        return implode(',', $this->tokens);
    }
}
