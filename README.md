# Rtry — retries with jitter, Retry-After, deadlines, budgets and circuit breakers
[![tests](https://github.com/Gohany/Rtry/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/Gohany/Rtry/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/Gohany/Rtry/branch/master/graph/badge.svg)](https://codecov.io/gh/Gohany/Rtry)

Rtry implements the [gohany/retry](https://github.com/Gohany/Retry) interfaces: it compiles a
one-line `rtry:` policy string, runs an operation under it, and works out how long to wait
between attempts.

- **Every delay is jittered.** A policy without `j` gets full jitter, so callers that failed
  together do not retry together.
- **Only retryable failures retry.** By default: 408, 429, 5xx, timeouts, connection errors,
  database deadlocks. A 422 runs once.
- **Retry-After is honoured**, as seconds or an HTTP date, and so is `RateLimit-Reset`.
- **Deadlines**: no attempt starts after `dl`, and Rtry never sleeps past it.
- **Durable retries**: `RtryScheduler::next()` returns "retry after N ms" or "give up, because
  …" without sleeping, for retries you store as a row or a queue message.
- **Retry budgets and circuit breakers** per downstream service, with stores you can share
  between processes.
- **Testable**: inject a PSR-20 clock, a sleeper and a seeded `\Random\Randomizer`, then check
  the exact sleeps a spec produces.

Requires PHP 8.2 or later. Version 2 changes behaviour: see [CHANGELOG.md](CHANGELOG.md).

## Install

```bash
composer require gohany/rtry
```

## Quick start

```php
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;

// Up to 5 attempts. Before jitter the waits are 200 ms, 400 ms, 800 ms and 1.6 s, capped at
// 8 s; full jitter draws each one from [0, that]. Retries 5xx, 429 and timeouts only.
$policy = (new RtryPolicyFactory())->fromSpec('rtry:a=5;d=200;m=exp;b=2;cap=8s;j=100%@full;on=5xx,429,ETIMEDOUT');

$retry = new Retry();
$response = $retry->try(fn ($attempt) => $client->get($url), $policy);
```

`try()` returns what the operation returned. When it gives up it rethrows the last attempt's
error, the same instance. A policy is immutable, so build it once and share it.

When you need to know why it stopped, use `tryOutcome()`:

```php
use Gohany\Retry\GiveUpReason;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;

$policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=250ms');
$result = (new Retry())->tryOutcome(fn ($attempt) => $client->get($url), $policy);

if ($result->isSuccess()) {
    $body = $result->value();
} elseif ($result->giveUpReason() === GiveUpReason::NotRetryable) {
    // A 4xx or a bug: fix the request rather than queue it again.
    $error = $result->lastError();
}
```

The operation receives an `AttemptContextInterface`: the attempt number, the delay it waited,
the time since attempt 1, the deadline budget left, and the context array you passed.

## What retries

`on` lists retryable tokens: a status (`503`), a family (`5xx`), or a tag (`ETIMEDOUT`).
`on=default`, and a spec without `on`, means
`408,429,5xx,ETIMEDOUT,ECONNRESET,ECONNREFUSED,NETWORK_ERROR,DEADLOCK,RATE_LIMITED`.
Anything else (another 4xx, a validation error, an error with no status and no tag) runs once.

Rtry turns a thrown error into a status and tags with a `RuleBasedFailureClassifier`:

- the status of a PSR-7 response returned by the error's `getResponse()` (Guzzle, and most
  PSR-18 clients' exceptions), or else an exception code between 100 and 599;
- `Retry-After`, `RateLimit-Reset(-After)` and `X-RateLimit-Reset(-After|-Ms)` headers on that
  response, tagged `RATE_LIMITED`;
- `ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED` and `NETWORK_ERROR` from the message and
  `Psr\Http\Client\NetworkExceptionInterface`;
- `DEADLOCK` from deadlock and serialization failures (MySQL 1213/1205, SQLSTATE 40001/40P01,
  Doctrine's `DeadlockException`).

Add your own rules, or pass a decider that replaces `on`:

```php
use Gohany\Rtry\Impl\Deciders\AlwaysRetryDecider;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\Rules\InstanceOfRule;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;

$classifier = RuleBasedFailureClassifier::default()
    ->addRule(new InstanceOfRule(\RedisException::class, null, ['NETWORK_ERROR']));
$factory = new RtryPolicyFactory(null, $classifier);
$policy = $factory->fromSpec('rtry:a=3;d=100');

// Every failure retries, 4xx included: only for operations whose every error is transient.
$always = $factory->fromSpec('rtry:a=3;d=100', new AlwaysRetryDecider());
```

## Retry-After and deadlines

With `fh=1` (the default) the next attempt waits at least as long as the server asked, even
above `cap`. `fh=0` ignores the headers.

`dl` is a budget from the start of attempt 1. When the next wait would reach it, Rtry gives up
with `DeadlineExceeded` instead of sleeping. `t` is an advisory per-attempt timeout: PHP cannot
interrupt a running call, so pass `$policy->attemptTimeoutMs()` on to your HTTP client. `h` is
a hedging hint that Rtry parses but does not act on; attempts run one at a time.

Sleeping in-process is for short calls under a deadline. Anything that can wait longer should
be a durable retry.

## Durable retries

A retry stored as a row or a queue message must not sleep in a web request. Ask the scheduler
for the delay and store it:

```php
use Gohany\Rtry\Impl\RtryAttemptContext;
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;

$policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=30s;cap=1h');
$scheduler = new RtryScheduler();

// $error was thrown by attempt number $job->attempts.
$outcome = RtryAttemptOutcome::fromError($error, $policy->failureClassifier());
$decision = $scheduler->next($policy, $outcome, new RtryAttemptContext($job->attempts, $policy->attempts(), 0, 0, null));

if ($decision->retry()) {
    $job->nextAttemptAt = $now->modify('+' . $decision->delayMs() . ' milliseconds');
} else {
    $job->failedBecause = $decision->giveUpReason()->value;
}
```

Leave the scheduler's `Randomizer` unset in production: each process gets its own unseeded
one. A seed shared by many workers makes them retry in step.

## Retry budgets and circuit breakers

Both are per key, usually one downstream service. The budget is gRPC-style retry throttling:
each retry costs a token, each success earns back a fraction of one, and retries stop while
fewer than half the tokens are left. The breaker opens after a number of failures in a window,
refuses calls while open, then lets exactly one probe through.

```php
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Budget\InMemoryRetryBudgetStore;
use Gohany\Rtry\Impl\Budget\TokenBucketRetryBudget;
use Gohany\Rtry\Impl\NativeClock;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;

$clock = new NativeClock();
$budget = new TokenBucketRetryBudget(new InMemoryRetryBudgetStore($clock), maxTokens: 10, tokenRatio: 0.1);
$breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, failureThreshold: 5, windowMs: 10_000, openMs: 30_000);

$retry = (new Retry($clock))->withGuard('search-api', $budget, $breaker);
$results = $retry->try(fn ($attempt) => $client->get($url), (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100'));
```

When the breaker is open, `try()` throws `Gohany\Retry\CircuitOpenException` without calling
the operation. A failure the policy does not retry, such as a 404, counts as a success for the
breaker: the service answered.

The in-memory stores cap one process. To share a budget or breaker across workers, implement
`RetryBudgetStoreInterface` (an atomic clamped add) and `CircuitBreakerStoreInterface` (a
versioned compare-and-set) on your shared store. Each call touches one key, so a clustered
Redis or Valkey needs no multi-key script.

Retrying a call with side effects (a payment, a webhook) needs an idempotency key on the call.

## The `rtry:` string

```
rtry:a=4;d=100;m=exp;b=2;cap=2s;j=100%@full;dl=10s;on=default;fh=1
```

| Key | Meaning | Default |
|---|---|---|
| `a` | attempts, including the first | required |
| `d` | base delay: the first retry in `exp`, the step in `lin` | required for `exp`, `lin` |
| `m` | `exp`, `lin` or `seq` | `exp` |
| `b` | exponential factor | 2 |
| `seq` | explicit delays, the first before attempt 2; `*` repeats the last | required for `seq` |
| `cap` | cap on the delay before jitter | none |
| `j` | jitter, `<window>[@full\|pm\|none]`; the window is a duration or a percent | `100%@full` |
| `t` | advisory per-attempt timeout | none |
| `dl` | deadline from the start of attempt 1 | none |
| `on` | retryable statuses, families and tags | `default` |
| `sa` | wait before attempt 1, not jittered | 0 |
| `fh` | honour Retry-After / RateLimit-Reset (`0` or `1`) | 1 |
| `h` | hedging hint, `<lanes>@<stagger>[&1]` | none |

Durations are milliseconds without a unit, or take `ms`, `s`, `m`, `h` (`1.5s`). Before attempt
n (n ≥ 2) the nominal delay is d·b^(n−2) in `exp` mode and d·(n−1) in `lin` mode. Full jitter
with a window w draws from [max(0, D − w), D]; `pm` from [max(0, D − w), D + w].

More examples: `rtry:a=3;m=lin;d=500ms;j=20%@pm` and `rtry:a=6;m=seq;seq=100,500,2s*;j=250@full`.
`$policy->canonicalSpec()` prints the normalised string. Unknown keys, repeated keys, and
values that do not fit are rejected with `\InvalidArgumentException`. The normative grammar is
gohany/retry's [SPEC.md](https://github.com/Gohany/Retry/blob/main/SPEC.md).

## Hooks and logging

```php
use Gohany\Rtry\Impl\Retry;

$retry = new Retry(logger: $logger);
$retry->setBetweenAttemptsHook(function ($attempt, $outcome, $policy, int $sleepMs, array $headers): void {
    // metrics: about to wait $sleepMs after attempt $attempt->attemptNumber()
});
$retry->setOnGiveUpHook(function ($attempt, $outcome, $policy, array $headers): void {
    // the last attempt failed and Rtry will not try again
});
```

A PSR-3 logger gets the attempt number, the exception class, the status and the tags. It never
gets the error message, which can carry request data.

## Testing your code

Inject a clock, a sleeper and a seeded `Randomizer`, and check the waits a spec produces:

```php
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;

$clock = new FrozenClock();
$sleeper = new RecordingSleeper($clock);
$retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer(42));

$retry->tryOutcome(
    fn ($attempt) => throw new \RuntimeException('Connection timed out'),
    (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0')
);

assert($sleeper->sleeps() === [100, 200]);
```

Run Rtry's own tests with `composer install && vendor/bin/phpunit`. They include gohany/retry's
contract test cases and run every PHP example in this README.

## License

MIT (see `LICENSE`).
