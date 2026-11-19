# Specification

Rtry implements version 2 of the retry interfaces and the `rtry:` policy string. The
normative text lives with the interfaces, in gohany/retry:
[SPEC.md](https://github.com/Gohany/Retry/blob/main/SPEC.md).

The draft v1 text that used to be here described keys (`mode=`, `hedge=`, `jmode=`) that Rtry
never parsed. It is superseded; see [CHANGELOG.md](CHANGELOG.md) for what changed.

Rtry-specific behaviour the SPEC leaves to implementations:

- **Classification.** A thrown error becomes a status code and tags through a
  `RuleBasedFailureClassifier` (README, "What retries"). A status read from an HTTP response
  anywhere on the error's `getPrevious()` chain decides: a retryable one (408, 429, 5xx) keeps
  the error's transport tags, any other gets none. Responses are found through `getResponse()`,
  Laravel's `$response` property, or a status accessor. Without a response, the exception code
  (100-599) is the status for `on`, and never silences tags. Tags come only from transport and
  deadlock evidence (types, cURL error numbers, errno names, SQLSTATEs, specific phrases
  including driver timeouts), never from a bare word, from a `LogicException`/`\Error`, or from the message of a
  database's answer or an error wrapping one that quotes it: a SQLSTATE in class 21, 22, 23, 42, 44, 45, P0 or
  40001/40P01, or a MySQL server error number (1000-1999, 3000-5999), read from the database
  error's own fields or from `SQLSTATE[...]` at the start of its message (README, "Database
  errors").
  `Retry-After` is read on any response; `RateLimit-Reset*` and `X-RateLimit-Reset*` only on a
  429, a 503, or a response whose `RateLimit-Remaining`/`X-RateLimit-Remaining` is 0. Either
  wait tags `RATE_LIMITED` on a 403, 408, 429 or 5xx, never on another 4xx or below 400.
  `RateLimit-Reset` values below 10^9 are delta seconds (the IETF RateLimit fields); larger
  values are an epoch time in seconds, or in milliseconds from 10^12.
- **Server waits.** `RtryScheduler::next()` follows SPEC 5.11 step 5, and also gives up with
  `DeadlineExceeded` when the server's wait exceeds its ceiling (a day by default). `Retry`,
  `RtryScheduler::outcome()`, and `RtryAttemptOutcome::fromError()` when given the policy, move the outcome's `notBeforeUnixMs`
  later by the policy's jitter window for that attempt, scaled down to at most the larger of
  1 s and 10 % of the server's wait, so a Retry-After is jittered above and never below. Whether a wait fits `dl` or the ceiling is decided by the server's time, not by
  the spread: when it fits, the spread never pushes it past either, and is scaled down into
  the room left before them (never clipped onto their last millisecond). `outcome()` takes `dl` from
  the context it is given and the ceiling from its scheduler. `fromError()` knows `dl` only when
  given the attempt's context, and a ceiling other than a day only when given it too.
- **Breakers.** By default a failure the policy does not retry counts as a success for the
  circuit breaker, and one it retries as a failure. A `BreakerVerdictInterface` given to
  `withGuard()` decides instead, given the outcome and whether the policy retries it
  (`ServiceFailureVerdict`: what the policy retries, a 408, 429 or 5xx read from a response,
  transport tags, and errors that show the service unreachable are failures; an exception code
  alone never is). It never changes the scheduler's decision for an attempt; through the breaker
  it changes whether the run goes on. `Retry` asks the breaker before each attempt and the budget before each retry.
  After a retryable failure it checks the breaker's state before sleeping: anything but
  closed ends the run at once with `CircuitOpen` and the operation's last error, without
  spending a budget token or waiting out the delay. A guard that throws never replaces the
  operation's value or error: the breaker fails open, the budget fails closed (no retry,
  `BudgetExhausted`), a failed `recordSuccess`/`recordFailure` is ignored, and each is logged
  as `retry.guard_error` with the exception's class only; README, "When a guard's store fails".
  `CircuitBreaker`'s optional `onTransition` callback (2.1) reports each change of state once,
  from the process whose compare-and-set made it; README, "Watching the breaker open and close".
- **Hedging and per-attempt timeouts** are parsed and exposed; Rtry acts on neither.
- **Logging.** The PSR-3 logger never receives an error's message. Nothing is logged at
  `error`: a failure the policy does not retry, and its give-up, are `debug`; a retryable
  failure and any other give-up are `warning`.
