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

- **A status read from an HTTP response decides.** Rtry looks at the error and every error in
  its `getPrevious()` chain for a response: `getResponse()` returning a PSR-7 response
  (Guzzle, PSR-18 clients) or one with `getStatusCode()` (Symfony HttpClient, whose headers it
  reads with `getHeaders(false)`), a public `$response` property with `status()` and
  `headers()` (Laravel's HTTP client `RequestException`), or a status accessor on the error
  itself (`getStatusCode()` as in the AWS SDK, `getHttpStatus()` as in Stripe's,
  `getHttpStatusCode()`). A 404 wrapped in a `RuntimeException('Request failed', 0, $e)` is
  still a 404.
- A retryable response status (408, 429, 5xx) also gets the error's transport tags, so
  `on=ETIMEDOUT` still matches a 504 that says it timed out. Any other response status (a 404,
  a 422, a 302, a 999) gets no tags at all: a 404 whose URL contains "timeout", or a 422 whose
  body says "card refused", runs once.
- **Without a response, the exception code** from 100 to 599 is the status for `on`, as in
  2.x, so `on=503` matches `new RuntimeException('...', 503)`. A code is not proof of HTTP
  (SMTP's 421, a driver's 409), so it never silences the tags below.
- **Tags come only from transport and deadlock evidence:** `ETIMEDOUT`, `ECONNRESET`,
  `ECONNREFUSED` and `NETWORK_ERROR` from `Psr\Http\Client\NetworkExceptionInterface`,
  Symfony HttpClient's `TransportExceptionInterface`, a cURL error number (`cURL error 28`),
  an errno name (`connect ECONNREFUSED 127.0.0.1:6379`), or a specific phrase such as
  "connection refused", "timed out", "connection reset" or a driver's timeout (PostgreSQL's
  "canceling statement due to statement timeout", MongoDB's "socket error or timeout",
  ORA-12170's "timeout occurred", Go's "i/o timeout"), on the error or any error in its chain;
  `DEADLOCK` from deadlock and lock failures (MySQL 1213/1205, SQLSTATE 40001/40P01,
  Doctrine's `DeadlockException`, "Deadlock found", PostgreSQL's lock timeout). A bare
  "timeout" or "refused" is not evidence: "timeout must be a positive integer" and "Payment
  refused by issuer" run once. A `LogicException` or an `\Error` (`TypeError`, `ValueError`)
  never gets tags from its message: it is a bug or a validation error.
- `Retry-After` on any response, and `RateLimit-Reset(-After)` and
  `X-RateLimit-Reset(-After|-Ms)` on a 429, a 503 or a response whose
  `X-RateLimit-Remaining` (or `RateLimit-Remaining`) is 0, set the next wait. Many APIs send
  `X-RateLimit-*` on every response, so with quota left those headers mean nothing.
- A 403, 408, 429 or 5xx carrying one of those waits is tagged `RATE_LIMITED`, which
  `on=default` retries. So GitHub's rate-limit 403s (`Retry-After`, or
  `X-RateLimit-Remaining: 0` with `X-RateLimit-Reset`) retry after the time GitHub names, and
  a plain 403 runs once. Any other 4xx with `Retry-After` runs once; list its status in `on`
  (`on=default,423`) to retry it, and the wait still comes from its `Retry-After`.

Tags from rules you add are kept whatever the status.

### Responses Rtry cannot find

Rtry reads the clients above without help: PSR-7 and PSR-18 clients (Guzzle), Symfony
HttpClient, Laravel's HTTP client, and SDKs with a status accessor (AWS, Stripe).

Any other client is a client Rtry cannot see the response of, and that matters. With no
response found, Rtry falls back to the exception code and the message: the code is the
status for `on`, but it cannot silence the message's transport words (SMTP's 421 and a
driver's 409 are codes in the same range, and a "421 Connection timed out" must retry). So a
4xx from such a client whose message carries a body saying "timed out" retries, and its
`Retry-After` is not read. If your client's exceptions carry the response somewhere else,
write an `ErrorResponseReaderInterface` and pass it to `RuleBasedFailureClassifier::default()`.
Rtry tries it first on each error in the chain, then its own reading, so PSR-7, Symfony and
Laravel errors keep working alongside it. For example, a client that keeps the response
behind a method of its own:

```php
use Gohany\Rtry\Contracts\ErrorResponse;
use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;

final class AcmeResponseReader implements ErrorResponseReaderInterface
{
    public function read(\Throwable $e): ?ErrorResponse
    {
        if (!$e instanceof \Acme\Http\ApiException) {
            return null;
        }
        return new ErrorResponse($e->httpReply()->code, $e->httpReply()->headers);
    }
}

$factory = new RtryPolicyFactory(null, RuleBasedFailureClassifier::default(new AcmeResponseReader()));
```

A reader that throws is treated as finding nothing.

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
above `cap` (gohany/retry SPEC 5.11). `fh=0` ignores the headers.

- **Jittered above, never below.** Callers told the same `Retry-After` would otherwise return
  in the same millisecond, so Rtry adds the policy's jitter window for that attempt on top: with
  full jitter and a 100 ms backoff, `Retry-After: 2` waits between 2000 and 2100 ms. `j=0`
  waits exactly what the server said. When the server's wait fits inside `dl`, the spread
  never pushes it past `dl` (on the durable path, build the outcome with the scheduler's
  `outcome()` and the attempt's context; see below).
- **Bounded.** A wait past `dl` gives up with `DeadlineExceeded` without sleeping. So does a
  wait longer than a day (`RtryScheduler::MAX_HEADER_WAIT_MS`; pass another ceiling to
  `RtryScheduler`'s constructor for durable retries that should wait longer, and build their
  outcomes with that scheduler's `outcome()` so the spread knows the same ceiling). The server's
  wait decides, not the jitter: `Retry-After: 86400` waits exactly a day with or without
  jitter, and `86401` gives up either way. A header asking
  for a year, a number too large for PHP, a negative or malformed value, or a word such as
  "tomorrow" never raises a PHP warning or a `TypeError`: absurd waits give up, unreadable
  ones are ignored.

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
// $remainingMs is what is left of your dl, or null without one.
$context = new RtryAttemptContext($job->attempts, $policy->attempts(), 0, 0, $remainingMs);

// outcome() classifies $error with the policy's classifier and spreads rows told the same
// Retry-After above it, inside dl and inside this scheduler's ceiling.
$outcome = $scheduler->outcome($error, $policy, $context);
$decision = $scheduler->next($policy, $outcome, $context);

if ($decision->retry()) {
    $job->nextAttemptAt = $now->modify('+' . $decision->delayMs() . ' milliseconds');
} else {
    $job->failedBecause = $decision->giveUpReason()->value;
}
```

Leave the scheduler's `Randomizer` unset in production: each process gets its own unseeded
one. A seed shared by many workers makes them retry in step.

`RtryScheduler` has no breaker. If the durable path reports to one, ask the verdict yourself,
with the outcome and whether the policy retries it:

```php
use Gohany\Retry\GiveUpReason;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\NativeClock;
use Gohany\Rtry\Impl\RtryAttemptContext;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RtryScheduler;

$clock = new NativeClock();
$breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, failureThreshold: 5, windowMs: 10_000, openMs: 30_000);
$policy = (new RtryPolicyFactory())->fromSpec('rtry:a=8;d=30s;cap=1h;on=5xx');
$scheduler = new RtryScheduler($clock);
$context = new RtryAttemptContext($job->attempts, $policy->attempts(), 0, 0, $remainingMs);

$outcome = $scheduler->outcome($error, $policy, $context);
$decision = $scheduler->next($policy, $outcome, $context);

$retryable = $decision->giveUpReason() !== GiveUpReason::NotRetryable;
if ((new ServiceFailureVerdict())->isServiceFailure($outcome, $retryable)) {
    $breaker->recordFailure('mail-api');
} else {
    $breaker->recordSuccess('mail-api');
}

if ($decision->retry()) {
    $job->nextAttemptAt = $now->modify('+' . $decision->delayMs() . ' milliseconds');
} else {
    $job->failedBecause = $decision->giveUpReason()->value;
}
```

Without the verdict, a refused connection under `on=5xx` would be reported as a success here
too. With it, that is a failure, a 404 is a success, and a 503 is a failure.

`outcome()` takes the clock, the randomness and the ceiling (`maxHeaderWaitMs`) from the
scheduler, and the attempt number from the context, so each is set once. It replaces
`RtryAttemptOutcome::fromError()`, which still works, but needs a ceiling other than the
default day passed to it as well as to the scheduler: given to the scheduler alone, a week-long
ceiling drops the spread above a two-day `Retry-After`, and an hour-long one gives up on a
`Retry-After: 3600` it should honour.

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
the operation. When a failure leaves the circuit open (it was the one that opened it, a probe
failed, or another worker opened it meanwhile), the run ends at once and `try()` rethrows that
failure; it does not sleep toward a retry the breaker would refuse, and it spends no budget
token. A failure the policy does not retry, such as a 404, counts as a success for the
breaker: the service answered.

That default reads the policy, not the service. A refused connection under `on=5xx` is not
retried, so it counts as a success too, and the circuit never opens however many there are; a
404 retried under `on=default,404` counts as a failure. To tell the breaker what happened to
the service instead, give `withGuard()` a verdict:

```php
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\NativeClock;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;

$clock = new NativeClock();
$breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, failureThreshold: 5, windowMs: 10_000, openMs: 30_000);

$retry = (new Retry($clock))->withGuard('search-api', null, $breaker, new ServiceFailureVerdict());
$results = $retry->try(fn ($attempt) => $client->get($url), (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;on=5xx'));
```

`ServiceFailureVerdict` counts a failed attempt as a failure for the breaker when:

- the policy retries it, as without a verdict, so by default the verdict only adds failures;
- an HTTP response the error carries, at any depth of its `getPrevious()` chain, has a status
  of 408, 429 or 5xx. An exception code is never read as a status here: an
  `InvalidArgumentException` with code 500, SMTP's 550 or AMQP's 530 is not the service failing;
- it carries one of the tags `ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED`, `NETWORK_ERROR` or
  `RATE_LIMITED`, or one you add;
- no response was read and the error shows the service could not be reached or could not take
  the call: a DNS failure (`cURL error 6`, `getaddrinfo`, `Could not resolve host`), a refused,
  reset or lost connection, `MySQL server has gone away`, `[2002]`, too many connections
  (`[1040]`), a PostgreSQL shutdown, a phpredis `read error on connection`, a broken pipe, or a
  certificate failure (`cURL error 60`). It reads transport exception types, cURL error numbers,
  errno names, SQLSTATE class 08 and a database driver's own connection error number. It never
  reads the message of a `LogicException` or an `\Error`.

Everything else (a 404, a 422, a `DEADLOCK`, a validation error) is a success, unless the
policy retries it. An outage none of these names, such as an SDK's own connection error, counts
only if the policy retries it or you add the tag your rule gives it:
`new ServiceFailureVerdict([...ServiceFailureVerdict::UNAVAILABLE_TAGS, 'SDK_TRANSPORT'])`.

`new ServiceFailureVerdict(countRetryable: false)` ignores what the policy retries, so a 404
retried under `on=default,404` keeps the circuit closed. Then a failure the policy retries
that the verdict does not recognise counts as a success, and a half-open probe that fails that
way closes the circuit. Add its tag. Or implement `Contracts\BreakerVerdictInterface`: it is
given the outcome and the policy's answer (`$retryable`).

The verdict changes only what the breaker is told. The scheduler's decision for each attempt
stays the policy's: whether to retry, the delay, and the give-up reason it gives. Through the
breaker, the verdict can change whether the run goes on. A failure it reports can open the
circuit, which ends the run with `CircuitOpen` and refuses later calls. A failure it calls a
success can keep the circuit closed, so a run that would have ended with `CircuitOpen` goes on
until the policy gives up. A failure the verdict counts is logged as `retry.failure` at
`warning` even when the policy does not retry it. A verdict that throws falls back to
`$retryable` for that attempt and is logged as `retry.guard_error` with `call` `verdict`.

### When a guard's store fails

A shared store can fail (Valkey restarting, a network partition). A guard that throws never
replaces the operation's own value or error:

- **Breaker: fails open.** If `allow()` or `state()` throws, the attempt goes ahead as if the
  circuit were closed. The policy's attempts, delays and deadline still bound the run.
- **Budget: fails closed.** If `tryAcquire()` throws, there is no retry: the run gives up with
  `BudgetExhausted` and the operation's last error. The first attempt is never blocked.
- **Reports are best effort.** If `recordSuccess()` or `recordFailure()` throws, the error is
  ignored and the operation's value or error comes back unchanged.

Each is logged at `warning` as `retry.guard_error` with `guard`, `call`, `key` and
`exception_class`, never the message (it can carry a host or a credential). So a store
outage costs at most the policy's own retries per call, and never the result.

The in-memory stores cap one process. To share a budget or breaker across workers, implement
`RetryBudgetStoreInterface` (an atomic clamped add) and `CircuitBreakerStoreInterface` (a
versioned compare-and-set) on your shared store. Each call touches one key, so a clustered
Redis or Valkey needs no multi-key script.

Retrying a call with side effects (a payment, a webhook) needs an idempotency key on the call.

### Watching the breaker open and close

Pass `onTransition` to log, count or alert on each change of a circuit's state. It runs once per
change (closed → open, open → half-open, half-open → closed or open), never per call, with a
`CircuitTransition`: the key, the state before and after, and the time from the breaker's clock.

```php
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\CircuitTransition;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\NativeClock;

$clock = new NativeClock();
$breaker = new CircuitBreaker(
    new InMemoryCircuitBreakerStore($clock),
    $clock,
    failureThreshold: 5,
    windowMs: 10_000,
    openMs: 30_000,
    onTransition: function (CircuitTransition $t) use ($logger): void {
        $logger->warning('circuit.transition', [
            'key' => $t->key,
            'from' => $t->from->value, // closed, open, half_open
            'to' => $t->to->value,
            'at_ms' => $t->atUnixMs,
        ]);
    },
);
```

The callback runs synchronously inside `allow()`, `recordSuccess()` or `recordFailure()`, so
keep it cheap. Anything it throws is swallowed: a broken listener never fails the call it is
watching.

**Across processes.** The callback runs in the process whose compare-and-set made the change.
Every process sharing a store reads the same versioned state and only one write from a given
version succeeds, so each change is reported by one process, however many share the store. A
shared `CircuitBreakerStoreInterface` keeps that guarantee if:

- `compareAndSet()` is atomic: it writes only when the stored version equals the expected one
  (`null` meaning absent), and every write changes the version.
- It returns `true` only when its own write happened. When it cannot tell (a timeout after the
  write was sent), it returns `false` and does not resend the write. The breaker then re-reads;
  if the write had landed it finds the change already made and reports nothing. A change is
  then reported at most once rather than exactly once, never twice.

One limit: a key nobody touches for `windowMs + 2 × openMs` expires from the store and reads as
closed again, with no transition reported. A count of open circuits built from the callback
should treat a circuit it has heard nothing about for that long as closed.

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

Nothing is logged at `error`: the caller gets the error back and decides how loud to be. A
failure the policy does not retry (`retry.failure`) and its `retry.give_up` are `debug`. A
retryable failure is `warning`, and so is a give-up because attempts, the deadline, the budget
or the circuit ran out. `retry.attempt` is `debug`, `retry.success` `info`.

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
