<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\RetryDeciderInterface;
use Gohany\Retry\RetryPolicyFactoryInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Impl\Parts\Hedge;
use Gohany\Rtry\Impl\Parts\Jitter;
use Gohany\Rtry\Impl\Parts\On;
use Gohany\Rtry\Impl\Parts\Sequence;

/**
 * Parses and validates an rtry: string (gohany/retry SPEC 6) into an immutable RtryPolicy.
 *
 *     rtry:a=5;d=200;m=exp;b=2;cap=8s;j=100%@full;on=5xx,ETIMEDOUT
 */
final class RtryPolicyFactory implements RetryPolicyFactoryInterface
{
    public const PREFIX = 'rtry:';

    /** Canonical key order (SPEC 6.4). */
    private const KEYS = ['a', 'd', 'm', 'b', 'seq', 'cap', 'j', 't', 'dl', 'on', 'sa', 'fh', 'h'];

    /**
     * @param RetryDeciderInterface|null $deciderOverride used instead of the on= tokens when
     *        fromSpec() is not given a decider
     * @param FailureClassifierInterface|null $classifier how thrown errors become a status,
     *        tags and a Retry-After time; RuleBasedFailureClassifier::default() when null
     */
    public function __construct(
        private readonly ?RetryDeciderInterface $deciderOverride = null,
        private readonly ?FailureClassifierInterface $classifier = null,
    ) {
    }

    public function fromSpec(string $spec, ?RetryDeciderInterface $decider = null): RtryPolicy
    {
        $values = $this->parse($spec);

        $mode = RtryPolicy::MODE_EXPONENTIAL;
        if (isset($values['m'])) {
            $mode = strtolower($values['m']);
            if (!in_array($mode, [RtryPolicy::MODE_EXPONENTIAL, RtryPolicy::MODE_LINEAR, RtryPolicy::MODE_SEQUENCE], true)) {
                throw new \InvalidArgumentException('Unknown mode (m): ' . $values['m']);
            }
        }
        if (isset($values['b']) && $mode !== RtryPolicy::MODE_EXPONENTIAL) {
            throw new \InvalidArgumentException('b is only allowed in exp mode');
        }

        $attempts = self::positiveInt('a', $values['a'] ?? throw new \InvalidArgumentException('rtry: a (attempts) is required'));
        $delay = self::duration('d', $values['d'] ?? null);
        $base = isset($values['b']) ? self::factor($values['b']) : 2.0;
        $sequence = isset($values['seq']) ? Sequence::make($values['seq']) : null;
        $cap = self::duration('cap', $values['cap'] ?? null);
        $jitter = isset($values['j']) ? Jitter::make($values['j']) : null;
        $timeout = self::duration('t', $values['t'] ?? null);
        $deadline = self::duration('dl', $values['dl'] ?? null);
        $on = isset($values['on']) ? On::make($values['on']) : null;
        $startAfter = self::duration('sa', $values['sa'] ?? null) ?? 0;
        $follow = isset($values['fh']) ? self::flag('fh', $values['fh']) : true;
        $hedge = isset($values['h']) ? Hedge::make($values['h']) : null;

        $canonical = [
            'a' => (string) $attempts,
            'd' => $delay !== null ? (string) $delay : null,
            'm' => $mode,
            'b' => Duration::formatNumber($base),
            'seq' => $sequence?->canonical(),
            'cap' => $cap !== null ? (string) $cap : null,
            'j' => $jitter?->canonical(),
            't' => $timeout !== null ? (string) $timeout : null,
            'dl' => $deadline !== null ? (string) $deadline : null,
            'on' => $on?->canonical(),
            'sa' => (string) $startAfter,
            'fh' => $follow ? '1' : '0',
            'h' => $hedge?->canonical(),
        ];
        $pairs = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $pairs[] = $key . '=' . $canonical[$key];
            }
        }

        return new RtryPolicy(
            attempts: $attempts,
            mode: $mode,
            delayMs: $delay,
            exponentialBase: $base,
            sequence: $sequence,
            capMs: $cap,
            jitter: $jitter,
            attemptTimeoutMs: $timeout,
            deadlineBudgetMs: $deadline,
            decider: $decider ?? $this->deciderOverride ?? $on?->decider(),
            startAfterMs: $startAfter,
            followHeaders: $follow,
            hedge: $hedge,
            failureClassifier: $this->classifier ?? RuleBasedFailureClassifier::default(),
            canonicalSpec: self::PREFIX . implode(';', $pairs),
        );
    }

    /** @return array<string,string> key => raw value, keys lower-cased */
    private function parse(string $spec): array
    {
        $body = trim($spec);
        if (stripos($body, self::PREFIX) === 0) {
            $body = trim(substr($body, strlen(self::PREFIX)));
        }
        if ($body === '') {
            throw new \InvalidArgumentException('Empty rtry spec');
        }

        $values = [];
        foreach (explode(';', $body) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $pos = strpos($pair, '=');
            if ($pos === false) {
                throw new \InvalidArgumentException('Malformed rtry pair, expected key=value: ' . $pair);
            }
            $key = strtolower(trim(substr($pair, 0, $pos)));
            $value = trim(substr($pair, $pos + 1));
            if (!in_array($key, self::KEYS, true)) {
                throw new \InvalidArgumentException('Unknown rtry key: ' . $key);
            }
            if (array_key_exists($key, $values)) {
                throw new \InvalidArgumentException('Repeated rtry key: ' . $key);
            }
            if ($value === '') {
                throw new \InvalidArgumentException('Empty value for rtry key: ' . $key);
            }
            $values[$key] = $value;
        }
        if ($values === []) {
            throw new \InvalidArgumentException('Empty rtry spec');
        }

        return $values;
    }

    private static function positiveInt(string $key, string $value): int
    {
        if (!preg_match('~^[0-9]+$~', $value) || (int) $value < 1) {
            throw new \InvalidArgumentException($key . ' must be an integer of at least 1: ' . $value);
        }
        return (int) $value;
    }

    private static function duration(string $key, ?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        try {
            return Duration::parseDurationMs($value);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($key . ' must be a duration (e.g. 250, 250ms, 1.5s, 2m, 1h): ' . $value, 0, $e);
        }
    }

    private static function factor(string $value): float
    {
        if (!preg_match('~^[0-9]+(?:\.[0-9]+)?$~', $value) || (float) $value < 1.0) {
            throw new \InvalidArgumentException('b must be a number of at least 1: ' . $value);
        }
        return (float) $value;
    }

    private static function flag(string $key, string $value): bool
    {
        if ($value !== '0' && $value !== '1') {
            throw new \InvalidArgumentException($key . ' must be 0 or 1: ' . $value);
        }
        return $value === '1';
    }
}
