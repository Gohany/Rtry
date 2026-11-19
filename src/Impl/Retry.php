<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\CircuitBreakerInterface;
use Gohany\Retry\CircuitOpenException;
use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\RetryBudgetInterface;
use Gohany\Retry\RetryerInterface;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Retry\RetryResultInterface;
use Gohany\Retry\SleeperInterface;
use Gohany\Rtry\Contracts\BreakerVerdictInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\RetryHooksInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;
use Throwable;

/**
 * Runs an operation under a policy, sleeping between attempts (SPEC 5.1).
 *
 * - try() returns the operation's value and rethrows the last attempt's error on give-up;
 *   tryOutcome() returns a RetryResult that also says why it stopped.
 * - Delays come from RtryScheduler: backoff, cap, jitter, Retry-After, deadline.
 * - Time comes from the one injected PSR-20 clock; waits go through the SleeperInterface.
 * - withGuard() adds a retry budget and/or circuit breaker for one downstream key, and
 *   optionally a verdict on what each failure tells the breaker. A failure
 *   that leaves the circuit open ends the run at once, without sleeping toward a retry the
 *   breaker would refuse. A guard that throws (its store is down) never replaces the
 *   operation's own value or error: the breaker fails open, the budget fails closed, a
 *   failed report is ignored, and each is logged as retry.guard_error (class only).
 * - Per-attempt timeouts (t) are advisory and hedging (h) is a hint: PHP cannot pre-empt
 *   a running callable, and attempts run one at a time.
 *
 * Sleeping here is for short synchronous calls under a deadline. A retry that can wait
 * should be stored with RtryScheduler::next()'s delay instead.
 */
final class Retry implements RetryerInterface, RetryHooksInterface
{
    private readonly ClockInterface $clock;
    private readonly SleeperInterface $sleeper;
    private readonly RtryScheduler $scheduler;
    private readonly Randomizer $random;
    private readonly FailureClassifierInterface $fallbackClassifier;

    private ?string $guardKey = null;
    private ?RetryBudgetInterface $budget = null;
    private ?CircuitBreakerInterface $breaker = null;
    private ?BreakerVerdictInterface $verdict = null;

    /** @var null|callable */
    private $betweenHook = null;
    /** @var null|callable */
    private $onGiveUpHook = null;

    /**
     * @param Randomizer|null $random leave null outside tests: an unseeded Randomizer per
     *        process. A seed shared by many workers makes them retry in step.
     */
    public function __construct(
        ?ClockInterface $clock = null,
        ?SleeperInterface $sleeper = null,
        private readonly ?LoggerInterface $logger = null,
        ?Randomizer $random = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->sleeper = $sleeper ?? new NativeSleeper();
        $this->random = $random ?? new Randomizer();
        $this->scheduler = new RtryScheduler($this->clock, $this->random);
        $this->fallbackClassifier = RuleBasedFailureClassifier::default();
    }

    /**
     * A copy that also consults a retry budget and/or a circuit breaker under $key, usually
     * one downstream service. The budget is asked before each retry and credited on success;
     * the breaker is asked before each attempt and told each attempt's result.
     *
     * Without $verdict, a failure the policy does not retry (a 4xx, say) counts as a success
     * for the breaker (the service answered) and one it retries as a failure. With one, the
     * verdict decides what each failed attempt tells the breaker, given the outcome and the
     * policy's answer: ServiceFailureVerdict counts what the policy retries plus 408, 429 and
     * 5xx read from a response and errors that show the service unreachable, so a refused
     * connection under on=5xx opens the circuit and a validation error never does.
     *
     * The verdict never changes the scheduler's decision for an attempt (retry or not, the
     * delay, its give-up reason). Through the breaker it changes whether the run goes on: a
     * failure it reports can open the circuit and end the run with CircuitOpen; one it calls a
     * success can keep the circuit closed, so the run goes on. Each call replaces the guard:
     * a later withGuard() without a verdict has none.
     */
    public function withGuard(
        string $key,
        ?RetryBudgetInterface $budget = null,
        ?CircuitBreakerInterface $breaker = null,
        ?BreakerVerdictInterface $verdict = null,
    ): self {
        if ($key === '') {
            throw new \InvalidArgumentException('A guard key cannot be empty');
        }
        $clone = clone $this;
        $clone->guardKey = $key;
        $clone->budget = $budget;
        $clone->breaker = $breaker;
        $clone->verdict = $verdict;
        return $clone;
    }

    public function setBetweenAttemptsHook(callable $hook): void
    {
        $this->betweenHook = $hook;
    }

    public function setOnGiveUpHook(callable $hook): void
    {
        $this->onGiveUpHook = $hook;
    }

    public function try(callable $operation, RetryPolicyInterface $policy, array $context = []): mixed
    {
        $result = $this->tryOutcome($operation, $policy, $context);
        if ($result->isSuccess()) {
            return $result->value();
        }
        $error = $result->lastError();
        \assert($error !== null);
        throw $error;
    }

    public function tryOutcome(callable $operation, RetryPolicyInterface $policy, array $context = []): RetryResultInterface
    {
        $maxAttempts = max(1, $policy->attempts());
        $classifier = $policy instanceof RtryPolicy ? $policy->failureClassifier() : $this->fallbackClassifier;

        $delay = $policy->startAfterMs();
        if ($delay > 0) {
            $this->sleeper->sleepMs($delay);
        }

        $firstMs = $this->nowMs();
        $deadlineMs = $policy->deadlineBudgetMs() !== null ? $firstMs + $policy->deadlineBudgetMs() : null;
        $lastError = null;

        for ($attempt = 1; ; $attempt++) {
            if (!$this->breakerAllows()) {
                $error = $lastError ?? new CircuitOpenException((string) $this->guardKey);
                return $this->giveUp($error, $attempt - 1, GiveUpReason::CircuitOpen);
            }

            $attemptContext = $this->context($attempt, $maxAttempts, $delay, $firstMs, $deadlineMs, $context);
            $this->log('debug', 'retry.attempt', ['attempt' => $attempt, 'max_attempts' => $maxAttempts, 'scheduled_delay_ms' => $delay]);

            try {
                $value = $operation($attemptContext);
            } catch (Throwable $error) {
                $lastError = $error;
                $metadata = $classifier->classify($error);
                foreach ($metadata->getContextPatch() as $k => $v) {
                    $context[$k] = $v;
                }
                $now = $this->nowMs();
                $outcome = RtryAttemptOutcome::fromMetadata($error, $metadata, $now);
                if ($policy->followHeaders() && $outcome->notBeforeUnixMs() !== null) {
                    // Spread above the server's time, never below it, and never past dl or
                    // the scheduler's ceiling when the server's own time fits them.
                    $outcome = $outcome->spreadAboveServerTime(
                        $now,
                        RtryScheduler::headerSpreadMs($policy, $attempt, $this->random),
                        $deadlineMs,
                        $this->scheduler->maxHeaderWaitMs(),
                    );
                }

                $decision = $this->scheduler->next(
                    $policy,
                    $outcome,
                    $this->context($attempt, $maxAttempts, $delay, $firstMs, $deadlineMs, $context),
                );
                $reason = $decision->giveUpReason();
                $serviceFailure = $this->isServiceFailure($outcome, $reason !== GiveUpReason::NotRetryable);

                // A failure the policy does not retry (a 404, a validation error) is the caller's
                // business, not a retry event: debug, unless the breaker counts it. Never log the
                // message: it can carry request data. Class, status and tags only.
                $this->log($reason === GiveUpReason::NotRetryable && !$serviceFailure ? 'debug' : 'warning', 'retry.failure', [
                    'attempt' => $attempt,
                    'exception_class' => self::className($error),
                    'status_code' => $outcome->statusCode(),
                    'tags' => $outcome->tags(),
                ]);

                $this->report('breaker', $serviceFailure ? 'recordFailure' : 'recordSuccess');

                // A circuit open now (this failure opened it, a probe failed, or another worker
                // opened it) would refuse the retry: give up without sleeping first.
                if ($reason === null && $this->circuitIsOpen()) {
                    $reason = GiveUpReason::CircuitOpen;
                }

                if ($reason !== null) {
                    $this->runGiveUpHook($attemptContext, $outcome, $policy, $metadata->getHeaders());
                    return $this->giveUp($error, $attempt, $reason);
                }

                if (!$this->budgetAllowsRetry()) {
                    $this->runGiveUpHook($attemptContext, $outcome, $policy, $metadata->getHeaders());
                    return $this->giveUp($error, $attempt, GiveUpReason::BudgetExhausted);
                }

                $delay = $decision->delayMs();
                $this->runBetweenHook($attemptContext, $outcome, $policy, $delay, $metadata->getHeaders());
                $this->sleeper->sleepMs($delay);
                continue;
            }

            $this->report('breaker', 'recordSuccess');
            $this->report('budget', 'recordSuccess');
            $this->log('info', 'retry.success', ['attempt' => $attempt, 'elapsed_ms' => $this->nowMs() - $firstMs]);

            return RetryResult::success($value, $attempt);
        }
    }

    /** Before each attempt. A breaker that throws (its store is down) lets the attempt run. */
    private function breakerAllows(): bool
    {
        if ($this->breaker === null) {
            return true;
        }
        try {
            return $this->breaker->allow((string) $this->guardKey);
        } catch (Throwable $e) {
            $this->guardError('breaker', 'allow', $e);
            return true;
        }
    }

    /**
     * After a retryable failure, before sleeping: is the circuit anything but closed? Open
     * refuses every caller and half-open has its probe out, so the retry would be refused.
     * A breaker that throws counts as closed.
     */
    private function circuitIsOpen(): bool
    {
        if ($this->breaker === null) {
            return false;
        }
        try {
            return $this->breaker->state((string) $this->guardKey) !== CircuitState::Closed;
        } catch (Throwable $e) {
            $this->guardError('breaker', 'state', $e);
            return false;
        }
    }

    /**
     * What a failed attempt tells the breaker: the verdict's answer when withGuard() was given
     * one and a breaker, otherwise whether the policy retries it. A verdict that throws falls
     * back to the policy's answer and is logged as retry.guard_error (call "verdict").
     */
    private function isServiceFailure(RtryAttemptOutcome $outcome, bool $retryable): bool
    {
        if ($this->breaker === null || $this->verdict === null) {
            return $retryable;
        }
        try {
            return $this->verdict->isServiceFailure($outcome, $retryable);
        } catch (Throwable $e) {
            $this->guardError('breaker', 'verdict', $e);
            return $retryable;
        }
    }

    /** Before each retry. A budget that throws refuses: no extra load while it cannot count. */
    private function budgetAllowsRetry(): bool
    {
        if ($this->budget === null) {
            return true;
        }
        try {
            return $this->budget->tryAcquire((string) $this->guardKey);
        } catch (Throwable $e) {
            $this->guardError('budget', 'tryAcquire', $e);
            return false;
        }
    }

    /**
     * Tell a guard an attempt's result. A guard that throws is logged and ignored: the
     * operation's own value or error is the outcome.
     *
     * @param 'breaker'|'budget' $guard
     * @param 'recordSuccess'|'recordFailure' $call
     */
    private function report(string $guard, string $call): void
    {
        $target = $guard === 'breaker' ? $this->breaker : $this->budget;
        if ($target === null) {
            return;
        }
        try {
            $target->{$call}((string) $this->guardKey);
        } catch (Throwable $e) {
            $this->guardError($guard, $call, $e);
        }
    }

    private function guardError(string $guard, string $call, Throwable $e): void
    {
        // Class only: a store error's message can carry a host, a DSN or a credential.
        $this->log('warning', 'retry.guard_error', [
            'guard' => $guard,
            'call' => $call,
            'key' => $this->guardKey,
            'exception_class' => self::className($e),
        ]);
    }

    /**
     * A give-up is never logged at error: the caller gets the error back and decides how loud
     * to be. Not retryable is debug; out of attempts, deadline, budget or circuit is warning.
     */
    private function giveUp(Throwable $error, int $attempts, GiveUpReason $reason): RetryResult
    {
        $this->log($reason === GiveUpReason::NotRetryable ? 'debug' : 'warning', 'retry.give_up', [
            'attempts' => $attempts,
            'reason' => $reason->value,
            'last_error_class' => self::className($error),
        ]);
        return RetryResult::gaveUp($error, $attempts, $reason);
    }

    /**
     * The class to log: an anonymous class's name carries a NUL byte and its file path after
     * "@anonymous", so it stops there.
     */
    private static function className(Throwable $e): string
    {
        $class = $e::class;
        $at = strpos($class, '@anonymous');
        return $at === false ? $class : substr($class, 0, $at + strlen('@anonymous'));
    }

    /** @param array<string,mixed> $context */
    private function context(int $attempt, int $maxAttempts, int $delay, int $firstMs, ?int $deadlineMs, array $context): RtryAttemptContext
    {
        $now = $this->nowMs();
        return new RtryAttemptContext(
            $attempt,
            $maxAttempts,
            $delay,
            max(0, $now - $firstMs),
            $deadlineMs !== null ? max(0, $deadlineMs - $now) : null,
            $context,
        );
    }

    private function nowMs(): int
    {
        return Clock::nowMs($this->clock);
    }

    /** @param array<string,mixed> $data */
    private function log(string $level, string $message, array $data): void
    {
        $this->logger?->log($level, $message, $data);
    }

    /** @param array<string,mixed> $headers */
    private function runBetweenHook(RtryAttemptContext $context, RtryAttemptOutcome $outcome, RetryPolicyInterface $policy, int $sleepMs, array $headers): void
    {
        if ($this->betweenHook === null) {
            return;
        }
        try {
            ($this->betweenHook)($context, $outcome, $policy, $sleepMs, $headers);
        } catch (Throwable) {
            // A hook must not break the retry.
        }
    }

    /** @param array<string,mixed> $headers */
    private function runGiveUpHook(RtryAttemptContext $context, RtryAttemptOutcome $outcome, RetryPolicyInterface $policy, array $headers): void
    {
        if ($this->onGiveUpHook === null) {
            return;
        }
        try {
            ($this->onGiveUpHook)($context, $outcome, $policy, $headers);
        } catch (Throwable) {
            // A hook must not break the retry.
        }
    }
}
