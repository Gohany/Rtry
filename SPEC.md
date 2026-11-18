# Specification

Rtry implements version 2 of the retry interfaces and the `rtry:` policy string. The
normative text lives with the interfaces, in gohany/retry:
[SPEC.md](https://github.com/Gohany/Retry/blob/main/SPEC.md).

The draft v1 text that used to be here described keys (`mode=`, `hedge=`, `jmode=`) that Rtry
never parsed. It is superseded; see [CHANGELOG.md](CHANGELOG.md) for what changed.

Rtry-specific behaviour the SPEC leaves to implementations:

- **Classification.** A thrown error becomes a status code and tags through a
  `RuleBasedFailureClassifier` (README, "What retries"). An error carrying an HTTP error status
  (400-599) is judged by that status alone: its message adds no tags, and a rate-limit header
  tags `RATE_LIMITED` only on a 429 or a 503. Errors without one (transport errors, including
  errno codes such as 111) keep the tags from their message and type. `RateLimit-Reset` values below 10^9
  are delta seconds (the IETF RateLimit fields); larger values are an epoch time in seconds,
  or in milliseconds from 10^12.
- **Breakers.** A failure the policy does not retry counts as a success for the circuit
  breaker. `Retry` asks the breaker before each attempt and the budget before each retry.
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
