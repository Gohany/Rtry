<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\CircuitBreakerInterface;
use Gohany\Retry\CircuitOpenException;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\RetryBudgetInterface;
use Gohany\Retry\RetryerInterface;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Retry\RetryResultInterface;
use Gohany\Retry\SleeperInterface;
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
 * - withGuard() adds a retry budget and/or circuit breaker for one downstream key.
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
    private readonly FailureClassifierInterface $fallbackClassifier;

    private ?string $guardKey = null;
    private ?RetryBudgetInterface $budget = null;
    private ?CircuitBreakerInterface $breaker = null;

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
        $this->scheduler = new RtryScheduler($this->clock, $random ?? new Randomizer());
        $this->fallbackClassifier = RuleBasedFailureClassifier::default();
    }

    /**
     * A copy that also consults a retry budget and/or a circuit breaker under $key, usually
     * one downstream service. The budget is asked before each retry and credited on success;
     * the breaker is asked before each attempt and told each attempt's result. A failure the
     * policy does not retry (a 4xx, say) counts as a success for the breaker: the service
     * answered.
     */
    public function withGuard(string $key, ?RetryBudgetInterface $budget = null, ?CircuitBreakerInterface $breaker = null): self
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A guard key cannot be empty');
        }
        $clone = clone $this;
        $clone->guardKey = $key;
        $clone->budget = $budget;
        $clone->breaker = $breaker;
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
            if ($this->breaker !== null && !$this->breaker->allow((string) $this->guardKey)) {
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
                $outcome = RtryAttemptOutcome::fromMetadata($error, $metadata, $this->nowMs());

                // Never log the message: it can carry request data. Class, status and tags only.
                $this->log('warning', 'retry.failure', [
                    'attempt' => $attempt,
                    'exception_class' => get_class($error),
                    'status_code' => $outcome->statusCode(),
                    'tags' => $outcome->tags(),
                ]);

                $decision = $this->scheduler->next(
                    $policy,
                    $outcome,
                    $this->context($attempt, $maxAttempts, $delay, $firstMs, $deadlineMs, $context),
                );
                $reason = $decision->giveUpReason();

                if ($this->breaker !== null) {
                    $reason === GiveUpReason::NotRetryable
                        ? $this->breaker->recordSuccess((string) $this->guardKey)
                        : $this->breaker->recordFailure((string) $this->guardKey);
                }

                if ($reason !== null) {
                    $this->runGiveUpHook($attemptContext, $outcome, $policy, $metadata->getHeaders());
                    return $this->giveUp($error, $attempt, $reason);
                }

                if ($this->budget !== null && !$this->budget->tryAcquire((string) $this->guardKey)) {
                    $this->runGiveUpHook($attemptContext, $outcome, $policy, $metadata->getHeaders());
                    return $this->giveUp($error, $attempt, GiveUpReason::BudgetExhausted);
                }

                $delay = $decision->delayMs();
                $this->runBetweenHook($attemptContext, $outcome, $policy, $delay, $metadata->getHeaders());
                $this->sleeper->sleepMs($delay);
                continue;
            }

            $this->breaker?->recordSuccess((string) $this->guardKey);
            $this->budget?->recordSuccess((string) $this->guardKey);
            $this->log('info', 'retry.success', ['attempt' => $attempt, 'elapsed_ms' => $this->nowMs() - $firstMs]);

            return RetryResult::success($value, $attempt);
        }
    }

    private function giveUp(Throwable $error, int $attempts, GiveUpReason $reason): RetryResult
    {
        $this->log('error', 'retry.give_up', [
            'attempts' => $attempts,
            'reason' => $reason->value,
            'last_error_class' => get_class($error),
        ]);
        return RetryResult::gaveUp($error, $attempts, $reason);
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
