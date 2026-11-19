# Changelog

## 2.4.1 — Unreleased

Fixes to the breaker verdict's matching from the re-verification of PR #7 and the hostile
review of PR #8. No public API changes. One retry change: a database's answer about the values
it was sent no longer gets transport tags from its message, so it runs once (under Fixed, with
what it reaches and the rule that brings a retry back).

### Fixed

- **A real 503 on the error counts, whatever the classifier.** `ServiceFailureVerdict` read
  `statusFromResponse()` false as "this error carries no response". False is also
  `FailureMetadata`'s default, so under any classifier other than `default()`
  (`new RuleBasedFailureClassifier([new MethodStatusRule()])`, or a classifier of your own
  returning `new FailureMetadata(503)`) a 503 the error carried was a success, and a half-open
  probe that got one closed the circuit. And when a rule replaced a 404's status, the verdict
  read the 404's message: `Not found: /search?q=connection refused` counted. The verdict now
  trusts only true; otherwise it reads the response on the error itself (false says only that
  the status is not the response's). `statusFromResponse()` itself is unchanged.
- **A database's answer about what it was sent cannot open a circuit, or retry.** MySQL echoes
  the submitted value, so `SQLSTATE[23000]: ... Duplicate entry 'broken pipe' for key
  'products.name'` let anyone who can submit a unique field open the database's circuit through
  the verdict, and `Duplicate entry 'timed out'` got `ETIMEDOUT` from the classifier (since
  2.3), so it retried under `on=default` and opened the circuit without a verdict. MySQL files
  many value errors under `HY000`, so a quantity field or a date filter was enough:
  `General error: 1366 Incorrect integer value: 'broken pipe'`, 1525, 1411, 1298, and the
  connect errors `[1049] Unknown database 'tenant_...'` and 1045. Such an error's message, and
  the message of any error wrapping it (Laravel's `QueryException` with its bindings, a DBAL
  wrapper), is no longer read for words. It is the database's answer when its SQLSTATE is in
  class 21, 22, 23, 42, 44, 45 (MySQL's `SIGNAL`) or P0 (PostgreSQL's `RAISE`) or is
  40001/40P01, or its number is a MySQL server error (1000 to 1999, 3000 to 5999; not 1105, 1429,
  1430, a connection number or an ORA number), read only from the database error itself:
  `errorInfo`, the code, `getSqlState()`, or PDO's `SQLSTATE[...]` and number at the very start
  of a message. Server errors whose own text names contention or a timeout keep their tags by
  number and that text (1205, 1213, 1614, 3058, 3132, 3133, 3699, 3781, 3840, 1639). Connection
  SQLSTATEs and numbers, client errors (2000 to 2999), ProxySQL's 9001 and transport types count
  as before. README, "Database errors", lists what is not covered: SQLite and Oracle (`HY000`
  for everything), MongoDB's E11000, `pg_query()` warnings, a framework exception with only the
  driver's code, and SQL with bindings appended to an error in another class.

  What now runs once under `on=default` that 2.4.0 retried: such an error whose message carries a
  timeout, reset or refused phrase, an errno name or a cURL number, whether in a value or in the
  server's own words (other than the server errors above that keep their tags). That includes real timeouts a database reports as its answer: SQL Server
  through ODBC files most server errors under `42000`, such as 8645 `A timeout occurred while
  waiting for memory resources` and a linked server's `Query timeout expired`; a function or
  trigger raising its own message (`SQLSTATE[P0001]: ... ledger service timed out`, `SIGNAL`
  under 45000). To retry one again, add a rule:
  `RuleBasedFailureClassifier::default()->addRule(new MessageRegexRule('~A timeout occurred while waiting for memory~', null, ['ETIMEDOUT']))`.
- **A SQLSTATE in request text no longer switches transport evidence off.** In the unreleased
  fix above, a `SQLSTATE[23000]` anywhere in any error's message made it an answer:
  `file_get_contents(http://127.0.0.1:9/search?q=SQLSTATE[23000]): Failed to open stream:
  Connection refused` ran once and told the breaker success, and as a half-open probe closed
  the circuit on a dead service. A SQLSTATE now counts only from the database error's fields or
  at the start of a message, and a transport type, connection SQLSTATE or connection number
  always counts. Not in 2.4.0.
- **A MySQL code in a message ends at a digit boundary.** PDO_OCI's
  `General error: 20060 … ORA-20060: credit limit exceeded` (an application error) was read as
  MySQL 2006, "server has gone away".
- **A lock wait timeout is contention, like a deadlock.** MySQL 1205 `Lock wait timeout
  exceeded` (and 3133) and PostgreSQL's lock timeout (55P03) get `DEADLOCK` and `ETIMEDOUT`; the
  verdict counted the `ETIMEDOUT`, so they opened the circuit under `on=5xx` while 1213
  `Deadlock found` did not. The verdict no longer counts `ETIMEDOUT` beside `DEADLOCK` when the
  error is a lock timeout, by its number and the server's text, its SQLSTATE, or a message that
  starts with a lock timeout's words. A timeout that only names a deadlock
  (`Read timed out ... for sku 'deadlock'`) still counts. Tags and retries are unchanged:
  `on=DEADLOCK` and `on=ETIMEDOUT` still match a lock timeout.
- **A connection SQLSTATE from `getSqlState()` counts.** A Doctrine DBAL `DriverException` with
  `getSQLState()` `08S01` and a neutral message was a success; `errorInfo`, a string code and the
  message were read, `getSqlState()` was not.
- The docs said a validation error never opens the circuit. It does when the policy retries
  it (`InvalidArgumentException('…', 500)` under `on=default`), as in 2.3.

### Docs

- README: "Database errors", and an SDK that carries the status only as its exception code
  (elasticsearch-php 7) needs a reader: without one its body's words are read.

### Tests

- `TagReviewFindingsTest`: each probe from PR #8's review, in the shapes MySQL 8.0.43 really
  throws through pdo_mysql and mysqli (1366, 1525, 1411, 1298, 1049, 1045, 1062, 1205, 2002,
  2006, ProxySQL's 9001), Laravel's appended bindings, DBAL's wrappers, the request-text
  outages, the timeout naming a deadlock, the rule that restores a retry, each SQLSTATE class
  and field, the server-range edges, each `UnavailableEvidence` phrase alone and the vendor
  transport types, with attempts and sleeps recorded under `on=5xx` and `on=default`.
- `RealMysqlTest` (group `mysql`, skipped unless `RTRY_MYSQL_HOST` is set): the same against a
  live MySQL.
- `ReVerifyFindingsTest`: the tests the review found not proving their names now do.
- `ReVerifyFindingsTest`: each probe from the re-verification (the three classifiers, the
  replaced 404, ORA-20060, duplicate entries echoing `broken pipe`, `timed out` and
  `connection refused` under `on=5xx` and `on=default` with the attempts and sleeps recorded,
  1205 against 1213), the four edges that survived mutation (a response without a status from
  100 to 599, the verdict's reader, a SQLSTATE from `errorInfo` and from a string code), and
  each entry of `UnavailableEvidence`'s cURL, errno, SQLSTATE and MySQL lists as the only
  signal.

## 2.4.0 — 2026-11-18

Additive: no change to retries, sleeps, breaker reports or logs for 2.3
callers that do not use the new arguments, and none to the gohany/retry interfaces. The one
change without them is which random draws a seeded `fromError()` makes (under Changed).

### Added

- **A breaker verdict.** `Retry::withGuard()` takes an optional fourth argument, a
  `Contracts\BreakerVerdictInterface`. Without one, as before, a failure the policy does not
  retry is a success for the breaker and one it retries is a failure, so a refused connection
  under `on=5xx` never opened the circuit, and a half-open probe that failed that way closed it.
  With one, each failed attempt tells the breaker what the verdict answers, given the outcome
  and the policy's answer (`isServiceFailure($outcome, $retryable)`).
  `Breaker\ServiceFailureVerdict` counts what the policy retries, a 408, 429 or 5xx read from a
  response at any depth of the error's `getPrevious()` chain, the tags `ETIMEDOUT`,
  `ECONNRESET`, `ECONNREFUSED`, `NETWORK_ERROR` and `RATE_LIMITED` (pass more for your own
  rules), and, when no response was read, errors that show the service unreachable: DNS
  failures, refused, reset or lost connections, `MySQL server has gone away`, too many
  connections, a phpredis read error, a broken pipe, a certificate failure. An exception code
  is never read as a status, so a validation error does not open the circuit unless the policy
  retries it. By default the
  verdict only adds failures to what the breaker hears without one;
  `new ServiceFailureVerdict(countRetryable: false)` counts the service's answer alone.
  The verdict never changes the scheduler's decision for an attempt (retry or not, the delay,
  its give-up reason); through the breaker it changes whether the run goes on. A failure it
  counts is logged as `retry.failure` at `warning` even when the policy does not retry it. A
  verdict that throws falls back to the policy's answer for that attempt and is logged as
  `retry.guard_error` with `call` `verdict`.
- **`RtryAttemptOutcome::statusFromResponse()`** says whether `statusCode()` was read from an
  HTTP response (true), from an exception code or a rule's constant (false), or is not known
  (null). `failure()` takes it as an optional fifth argument; `fromMetadata()` takes it from
  Rtry's `FailureMetadata`. The classifier's `FailureMetadata::isFromResponse()` is now false
  when a later rule's status replaced the response's.
- **`RtryScheduler::outcome($error, $policy, $context, $classifier = null)`** builds the
  outcome for `next()` on the durable path from the scheduler's own clock, randomness and
  ceiling, the context's attempt number and `dl`, and the policy's classifier. With a ceiling
  set on the scheduler alone, `RtryAttemptOutcome::fromError()` assumed a day: a week-long
  ceiling dropped the spread above a two-day `Retry-After`, and an hour-long one gave up on a
  `Retry-After: 3600`. `fromError()` keeps its signature; the README's durable example now
  uses `outcome()`.

### Changed

- `RtryAttemptOutcome::fromError()` (and so `RtryScheduler::outcome()`) no longer draws from
  the `Randomizer` when there is nothing to spread (no server time, or `fh=0`), so a seeded
  durable run gives the same delays as `Retry` with the same seed. The spread's distribution
  is unchanged. With `fh=0` the outcome's `notBeforeUnixMs()` is the server's time unspread;
  the scheduler ignores it there either way.

### Tests

- `VerdictSignalsTest`: the cases from the PR #7 hostile review. Code-only statuses (a
  validation error with code 500, SMTP 550, AMQP 530) keep the circuit closed; a status read
  from a response counts at any depth; untagged outages count and reopen a half-open circuit;
  five verdicts give the same attempts, sleeps and give-up reasons for six errors; a verdict
  that throws on a failure the policy does not retry reports a success; a seeded durable run
  matches `Retry`.
- Twelve 2.3.0 edges that were right but untested are pinned: a Laravel response whose
  `headers()` throws, a status below 100 on its `$response`, `getResponse()` read before
  `$response`, `RATE_LIMITED` on a 408 and a 599 and not on a 600, `X-RateLimit-Remaining: 1`
  and a non-numeric remaining count, "lock timeout" in a `RuntimeException` message, an errno
  name with a letter on either side, and `fromError()`'s ceiling argument.

## 2.3.0 — 2026-11-18

No change to the gohany/retry interfaces and no method removed; every
constructor and method change is an optional argument. Some behaviour changes on purpose. Each
is a fix for a failure that retried when it should not have, or the reverse. They are listed
under Changed so you can check them against your specs.

### Changed

- **A status read from an HTTP response decides, wherever it sits on the error.** Rtry looks
  along the `getPrevious()` chain for a response (`getResponse()`, PSR-7 or Symfony-shaped),
  Laravel's `RequestException` `$response` property (`status()`, `headers()`), or a status
  accessor (`getStatusCode()`, `getHttpStatus()`, `getHttpStatusCode()`). A
  retryable status (408, 429, 5xx) keeps the error's transport tags, as in 2.2. Any other
  (a 4xx, a 1xx-3xx, a status above 599) gets no tags. A wrapped 404 whose URL says
  "timeout", or a 422 whose body says "card refused", used to retry under `on=default`; it
  now runs once. Laravel's HTTP client is read without a reader: a 422 whose body says "timed
  out" used to retry, and a 429 or 503 used to ignore its `Retry-After`; now the status
  decides and the header is honoured.
- **Tags need transport evidence.** `ETIMEDOUT`, `ECONNREFUSED` and their siblings come
  from transport types (PSR-18 `NetworkExceptionInterface`, Symfony HttpClient
  `TransportExceptionInterface`), a cURL error number, an errno name (`ECONNREFUSED`,
  `ETIMEDOUT`, `ECONNRESET`), or a specific phrase ("connection refused", "timed out",
  "connection reset", "timeout waiting", ...), on the error or anything in its chain. Driver
  timeouts 2.2 retried still retry: PostgreSQL's statement and lock timeouts (SQLSTATE 57014,
  55P03; the lock timeout is also `DEADLOCK`), MongoDB's "socket error or timeout", Oracle's
  ORA-12170 and libmemcached's "timeout occurred", and Go's "i/o timeout". They no longer come
  from a bare "timeout" or "refused". A `LogicException` or
  an `\Error` never gets tags from its message. `InvalidArgumentException('timeout must be a
  positive integer')` and `DomainException('Payment refused by issuer')` used to retry; they
  now run once. If you relied on a word match, add a `MessageRegexRule` or an `InstanceOfRule`.
- **An exception code is still a status for `on`, as in 2.x, but it never silences tags.**
  `new RuntimeException('421 4.4.2 Connection timed out', 421)` (SMTP) and a deadlock coded
  409 keep `ETIMEDOUT` and `DEADLOCK` and retry under `on=default`.
- **Rate-limit reset headers only when the limit was hit.** `RateLimit-Reset(-After)` and
  `X-RateLimit-Reset(-After|-Ms)` used to set a wait on any response. Many APIs send them
  on every response, so a 502 could wait out an hour. They now set a wait only on a 429, a
  503, or a response whose `RateLimit-Remaining` / `X-RateLimit-Remaining` is 0.
- **`RATE_LIMITED` only on a status that can mean it.** It is tagged when one of those waits,
  or `Retry-After`, is found on a 403, 408, 429 or 5xx. A 403 that is a rate limit (GitHub
  sends `Retry-After`, or `X-RateLimit-Remaining: 0` with `X-RateLimit-Reset`) retries under
  `on=default` as in 2.2, waiting what the server said, and `on=RATE_LIMITED` still matches a
  500 with `Retry-After`. Two changes from 2.2: any other 4xx (a 404, a 422) carrying
  `Retry-After` now runs once, and `RATE_LIMITED` is never tagged below 400. To retry such a
  4xx, list its status in `on` (`on=default,423`); the wait is still read from its
  `Retry-After`.
- **A server's wait is jittered above, never below.** In-process (`Retry`), and for durable
  callers that pass the policy to `RtryAttemptOutcome::fromError()`, a `Retry-After` gets the
  policy's jitter window for that attempt added on top. Callers told "2 s" no longer return in
  the same millisecond. `j=0` waits exactly what the server said. When the server's own wait
  fits inside `dl`, the spread never pushes it past `dl`; on the durable path that needs the
  attempt's context passed to `fromError()` (its new optional `$context` argument), since `dl`
  lives there. `RtryScheduler::next()` itself is unchanged (gohany/retry SPEC 5.11 step 5).
- **A server's wait over a day gives up** with `DeadlineExceeded` instead of sleeping
  (`RtryScheduler::MAX_HEADER_WAIT_MS`, or the scheduler's new optional `$maxHeaderWaitMs`
  argument). The server's own wait decides, not the jitter on top of it: `Retry-After: 86400`
  is honoured with or without jitter, and the spread never pushes a wait that fits past the
  ceiling. Waits under that are still honoured above `cap`, as the SPEC requires.
- **No give-up is logged at `error`.** A failure the policy does not retry and its give-up are
  logged at `debug` (`retry.failure` was `warning`, `retry.give_up` was `error`). Any other
  give-up (attempts, deadline, budget, circuit) is logged at `warning`, not `error`. If you
  alert on `retry.give_up` at `error`, alert on `warning` or use `setOnGiveUpHook()`.

### Fixed

- A response header can no longer replace the operation's error. `Retry-After:
  @9223372036854776`, `9223372036854770`, `99999999999999999999` or `1e400`, and the
  same in the `RateLimit-Reset*` headers, used to throw a `TypeError` or (on PHP 8.5) an
  `E_WARNING` out of `try()`, or wrap to a negative wait. Values are parsed without
  float-to-int casts: absurd waits give up, unreadable ones are ignored. "tomorrow" and
  "next year" are no longer HTTP dates. The IMF-fixdate, RFC 850 and asctime formats are
  read, and so is a date-shaped string with a zone such as `+0000`.
- A header value that is a nested list no longer raises "Array to string conversion".
- An anonymous exception class is logged as `RuntimeException@anonymous`, without the NUL
  byte and file path PHP appends to its name.

### Added

- `ErrorResponseLocator`: finds the response on an error, wherever it sits, and reads each
  error once for all the rules that share it. `Contracts\ErrorResponseReaderInterface` and
  `Contracts\ErrorResponse` (a status and lower-cased headers) let you add a reader for a
  client that keeps its response somewhere else. Pass it to
  `RuleBasedFailureClassifier::default($reader)`.
  It is tried first, and the default reading still follows, so PSR-7 errors keep working
  next to it. README, "Responses Rtry cannot find".
- `Rules\ResponseStatusRule`: the status from the locator. `default()` keeps
  `MethodStatusRule(['getResponse'])` first, as in 2.x, so `hasRuleOfType(MethodStatusRule::class)`
  is still true.
- `FailureMetadata::isFromResponse()` and its optional `$fromResponse` constructor argument,
  `RtryScheduler::headerSpreadMs()` and `RtryScheduler::maxHeaderWaitMs()`, and
  `RtryAttemptOutcome::fromError()`'s optional `$policy`, `$failedAttempt`, `$random`,
  `$context` and `$maxHeaderWaitMs` arguments.

## 2.2.0 — 2026-11-18

No interface changes; two behaviour fixes for callers using `withGuard()`.

### Fixed

- **No sleep after the circuit opens.** After a retryable failure, `Retry` now checks the
  breaker's state before sleeping. If the circuit is anything but closed (this failure opened
  it, a half-open probe failed, or another worker opened it), the run ends at once with
  `CircuitOpen` and the operation's own last error, which `try()` rethrows. 2.0 and 2.1 slept
  the next delay first and only then found the circuit open; no budget token is spent on that
  refused retry any more either. A spec run `rtry:a=5;d=100;j=0;on=5xx` against a breaker
  with threshold 2 now sleeps `[100]`, not `[100, 200]`.
- **A failing guard never hides the outcome.** A breaker or budget that throws (its store is
  down) no longer replaces the operation's value or error. The breaker fails open, the budget
  fails closed (no retry, `BudgetExhausted`, with the operation's error), and a failed
  `recordSuccess()`/`recordFailure()` is ignored. Each is logged at `warning` as
  `retry.guard_error` with the exception's class, never its message. README, "When a guard's
  store fails".

## 2.1.0 — 2026-11-17

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
