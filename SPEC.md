# Specification

Rtry implements version 2 of the retry interfaces and the `rtry:` policy string. The
normative text lives with the interfaces, in gohany/retry:
[SPEC.md](https://github.com/Gohany/Retry/blob/main/SPEC.md).

The draft v1 text that used to be here described keys (`mode=`, `hedge=`, `jmode=`) that Rtry
never parsed. It is superseded; see [CHANGELOG.md](CHANGELOG.md) for what changed.

Rtry-specific behaviour the SPEC leaves to implementations:

- **Classification.** A thrown error becomes a status code and tags through a
  `RuleBasedFailureClassifier` (README, "What retries"). `RateLimit-Reset` values below 10^9
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
- **Logging.** The PSR-3 logger never receives an error's message.
