# Changelog

## 2.1.0 — unreleased

Additive: no change for 2.0 callers, and none to the gohany/retry interfaces.

### Added

- `CircuitBreaker` takes an optional `onTransition` callback, `callable(CircuitTransition): void`,
  as its sixth constructor argument. It runs once per change of a circuit's state
  (closed → open, open → half-open, half-open → closed or open), never per call, in the
  process whose compare-and-set made the change, so a change is reported once however many
  processes share the store. Anything it throws is swallowed.
- `Breaker\CircuitTransition`: the key, `from` and `to` (`Gohany\Retry\CircuitState`), and
  `atUnixMs` from the breaker's clock (`at()` as a `DateTimeImmutable`).
- README: what a shared `CircuitBreakerStoreInterface` must do to keep transitions reported
  once across processes, and the one gap (a key that expires while open reads as closed with
  no transition).

## 2.0.0 — 2026-11-17

Rtry 2 implements gohany/retry 2.0 and fixes the 1.x backoff bugs. **Several behaviours
change**; read "Behaviour changes" before upgrading.

### Requirements

- PHP `^8.2` (was `>=7.4`), `gohany/retry` `^2.0` (was `^1.0`).

### Behaviour changes

- **A spec without `on` no longer retries everything.** 1.x attached an always-retry decider to
  every spec (the `on` tokens were discarded). Now a spec without `on` means `on=default`:
  `408, 429, 5xx, ETIMEDOUT, ECONNRESET, ECONNREFUSED, NETWORK_ERROR, DEADLOCK, RATE_LIMITED`.
  **An error with no status and no recognised tag (a `LogicException`, a validation error,
  a plain `RuntimeException('failed')`) now runs once.** Add tags with your own classifier
  rules, list the tokens you want in `on`, or pass `new AlwaysRetryDecider()` to `fromSpec()`.
- **`on` is honoured.** `on=5xx,ETIMEDOUT` no longer retries a 422.
- **Backoff waits.** Exponential mode multiplied `sa` instead of `d`, so with no `sa` every
  wait was 0 ms; linear mode was always 0 ms; and the default `cap` of 0 capped every wait to
  0 ms. Now the wait before attempt n is d·b^(n−2) (`exp`) or d·(n−1) (`lin`), with no cap
  unless `cap` is set. Code that "worked" because it never waited will now wait.
- **Jitter by default.** A spec without `j` gets full jitter, [0, D]. `j=0` or `j=none` turns
  it off. `full` with a window w now draws from [max(0, D − w), D] (1.x drew from [0, w],
  ignoring the delay: `j=100@full` on an 8 s delay waited 0–100 ms).
- **Randomness.** Jitter draws from an injected `\Random\Randomizer`, unseeded by default. The
  policy seed (`setSeed()`) is gone: a seed shared by every worker synchronised them.
- **`sa` waits once before attempt 1 and is not jittered.** 1.x re-rolled jitter each time
  `startAfterMs()` was read.
- **Retry-After works from a string spec.** The rate-limit rule is now always attached, so
  `Retry-After` (seconds or HTTP date) and `RateLimit-Reset` are honoured with `fh=1` (the
  default), even above `cap`. A small `RateLimit-Reset` is delta seconds (1.x read it as an
  epoch in 1970 and ignored it).
- **Statuses from PSR-7 responses.** An error with a `getResponse()` returning a PSR-7
  response is classified by that response's status, with or without rate-limit headers.
- **`try()` returns the operation's value** and rethrows the last attempt's error on give-up
  (1.x declared an outcome object and, in one path, returned one instead of throwing).
  `tryOutcome()` is new and returns a `RetryResult` with the value or last error, the attempt
  count and a `GiveUpReason`.
- **Deadline.** `dl` counts from the start of attempt 1 (after `sa`) and is a duration only
  (1.x also accepted a date). Rtry gives up with `DeadlineExceeded` rather than sleep past it,
  and `sa` no longer throws a `RuntimeException` when it exceeds `dl`.
- **One clock.** Elapsed time and the deadline both come from the injected PSR-20 clock (1.x
  used `microtime()` for one of them).
- **Logs** no longer include the error message (it can carry request data): class, status
  and tags only.
- **Precedence**: a decider passed to `fromSpec()` now wins over the factory's constructor
  override.

### `rtry:` string

The keys are gohany/retry 2.0's: `a d m b seq cap j t dl on sa fh h`, validated per its SPEC §6.

- `m` is the mode key. `mode=` (shown in the 1.x README) is rejected as an unknown key.
- `d` is only the base delay; it no longer switches the mode to `lin`.
- Rejected now: a missing or non-integer `a`; `d` missing in `exp`/`lin` or present in `seq`;
  `seq` outside `seq` mode, or too short for `a` without a trailing `*`; `b` below 1 or outside
  `exp`; `fh` other than `0`/`1`; a jitter percent above 100; a hedge cancel policy other than
  `0`/`1`; an empty value.
- `canonicalSpec()` follows SPEC §6.4 (input keys only, fixed order, milliseconds).

### API

- `RtryPolicy` is immutable: every setter, `setSeed()`, `resetCursor()` and the cursor are
  gone. Build one with `RtryPolicyFactory::fromSpec()` or its constructor (named arguments).
  `nextDelayMs()` is replaced by `nominalDelayMs(int $attemptNumber)` (before cap and jitter);
  `capMs()` returns `?int`; `jitter()` never returns null.
- `RtryPolicyFactory::getLastParts()` and `toSpec()` are removed; use `canonicalSpec()`.
- Removed contracts: `RtryPolicyInterface`, `RtryAttemptContextInterface`, `PartInterface`,
  `GeneratesRulesInterface`, `TokenDeciderInterface` and Rtry's `SleeperInterface` (use
  `Gohany\Retry\SleeperInterface`). Removed parts: `Attempts`, `Base`, `Cap`, `Deadline`,
  `Delay`, `FollowHeaders`, `Mode`, `StartAfter`, `Timeout`, `Part`.
- `Parts\Jitter`, `Parts\Sequence`, `Parts\Hedge` and `Parts\On` are value objects with
  `make()` and `canonical()`. `Sequence::delayMsAt(n)` replaces `nextDelayMs()`/`reset()`
  /`delayByPosition()`; the first delay is the one before attempt 2.
- `OnTokensDecider` is immutable (no `setTokens()`/`getTokens()`); `new OnTokensDecider()`
  means `on=default`.
- `Retry::__construct(?ClockInterface, ?SleeperInterface, ?LoggerInterface, ?Randomizer)`:
  a fourth argument for the Randomizer.
- `RtryAttemptOutcome` is immutable: build it with `success()`, `failure()`, `fromError()` or
  `fromMetadata()`; it carries `notBeforeUnixMs()`. `RtryAttemptContext` is immutable.

### Added

- `RtryScheduler` (`Gohany\Retry\RetrySchedulerInterface`): the delay or give-up reason for a
  failed attempt without sleeping, for durable retries.
- `Retry::withGuard(string $key, ?RetryBudgetInterface, ?CircuitBreakerInterface)`.
- `Budget\TokenBucketRetryBudget` + `Budget\InMemoryRetryBudgetStore`;
  `Breaker\CircuitBreaker` + `Breaker\InMemoryCircuitBreakerStore`. Shared stores (Redis,
  Valkey) implement gohany/retry's store interfaces.
- `RuleBasedFailureClassifier::default()`.
- Tests: every gohany/retry contract test case, `SpecToSleepsTest` (real specs, recorded
  sleeps), and `ReadmeExamplesTest` (every README example runs). CI runs PHP 8.2 and 8.5.

### Not in this release

- Hedging and pre-emptive per-attempt timeouts: `h` and `t` are parsed and exposed as hints.
