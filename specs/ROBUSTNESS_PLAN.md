# PhpRedis Command Fuzzer Robustness Plan

## Objective

Make the fuzzer more effective at finding release-blocking defects in
`Relay\Relay` and `Relay\Cluster`, while preserving deterministic workloads,
useful diagnostics, safe defaults, and support for application-owned clients.

The most valuable direction is to evolve the suite from primarily randomized
command execution and reply-type counting into an outcome-aware, stateful,
differential testing tool.

## Environment note

Release testing is performed with explicitly selected PHP binaries rather than
the system-wide PHP executable. For example:

```text
/home/mike/dev/phpfarm/src/php-8.5.0-debug/sapi/cli/php
```

The in-tree build wrapper injects the full Relay source commit into the
extension, so the Git SHA reported by `php --ri relay` should match the checkout
used for that build. Release reports should record all of the following:

- Full PHP binary path and `PHP_VERSION`.
- Debug/NTS/ZTS status.
- Relay Git SHA reported by the extension, verified against the source
  checkout.

## Priority 0: Preserve every outcome and failure signal

### Problem

`Commands\Command::cmd()` currently catches client-thrown `Exception` objects
and converts them to `false`. It also logs and clears `getLastError()`. As a
result:

- Exceptions produced by Relay's `OPT_THROW_ON_ERROR` can disappear.
- Expected Redis errors and unexpected client failures become
  indistinguishable.
- The outer exception reporting in `Fuzzer` generally sees only `Error`
  subclasses or failures that occur outside `Command::cmd()`.
- The existing exception unit test throws from the logger and therefore does
  not cover an exception thrown by the client method itself.

Mixed-client results are also aggregated only by command. They do not identify
the client instance or whether the normal or raw path ran. A successful
PhpRedis reply can therefore hide Relay returning only `false` for the same
command name.

### Proposed outcome model

Record an outcome for every invocation containing at least:

- Command name and generated case/variant name.
- Client identity, class, and stable index within the run.
- Normal, raw, or proxy operation.
- Reply type and a bounded normalized representation or digest of the value.
- `getLastError()` before it is cleared.
- Captured PHP warnings.
- Thrown exception class, message, and code.
- Execution duration.
- Client mode before and after the call when available.
- Hash-slot policy for the generated case.

Expected Redis errors, PHP warnings, and thrown exceptions should remain three
separate outcome categories.

### Initial implementation tasks

1. Stop converting client exceptions to `false` inside `Command::cmd()`.
2. Capture server/client errors as structured data rather than only logging
   them.
3. Attribute all replies, warnings, errors, and exceptions to a client and
   operation.
4. Add a regression test whose overridden Redis client method throws a
   `RuntimeException`.
5. Make `--catch` search structured Redis errors in addition to warnings and
   exceptions.

### Implementation status

Implemented in the first outcome-model iteration:

- `FuzzResult::$outcomes` now contains one typed `InvocationOutcome` per
  scheduled step while retaining the aggregate command report.
- Outcomes identify the client and normal/raw path and separately record a
  bounded reply summary, all Redis errors observed during the invocation, PHP
  warnings, and thrown exception details.
- Outcomes also record elapsed time, client modes before and after, and the
  generated key slot policy.
- Client exceptions now reach `Fuzzer` instead of being converted to `false`.
- Redis errors are captured before clearing, including errors intentionally
  excluded from noisy logging, and are searched by `catchPattern`.
- Unit coverage includes a client method that throws and a structured Redis
  error that triggers `catchPattern`.
- Human-readable diagnostic aggregation uses narrow regex fingerprints for
  volatile values such as generated `NOGROUP` keys, while outcomes retain the
  exact original diagnostic text.

Named variants remain `null` until the case/variant scheduler work, and proxy
operations remain deferred to the populated-state work below.

## Priority 1: Add a true differential mode

### Problem

A mixed-client run currently selects one random eligible client for each step.
PhpRedis and Relay do not receive the same generated call, and their resulting
values are never compared.

### Proposed design

Separate argument generation from command execution with a reusable case
object, conceptually:

```text
FuzzCase
  command
  variant
  operation
  concrete arguments
  slot policy
  expected error category, if deliberately invalid
```

Execute the same case against mirrored clients using isolated key namespaces or
separate disposable Redis databases/instances. Normalize only documented API
differences; retain an explicit allowlist with reasons rather than silently
coercing outcomes.

### First differential oracle: cold versus warm cache

This should be the first focused implementation because much of the current
Relay release work concerns cache-served commands and error parity.

For each selected cacheable read:

1. Populate deterministic state through a control client.
2. Execute a cold Relay read.
3. Repeat it to force a warm-cache path.
4. Compare both results with PhpRedis or a cache-disabled Relay client.
5. Mutate through a second client.
6. Verify invalidation and compare the subsequent result again.

Compare values, reply types, `getLastError()`, and exception behavior. Run the
matrix under:

- Standalone and cluster clients.
- Cache enabled and disabled.
- Client invalidations enabled and disabled.
- `OPT_THROW_ON_ERROR` enabled and disabled.
- PhpRedis compatibility enabled and disabled where differences are expected
  and documented.
- Every supported serializer and compressor.
- Allow and ignore cache patterns.
- Correct types, missing keys, partially cached values, and wrong types.

### Implementation status

The first differential-oracle slice is implemented as an explicit opt-in mode:

- An ordered reference/Relay pair receives the same concrete generated normal
  call for atomic commands marked `CACHED`; the normal randomized workload
  remains unchanged when differential mode is disabled.
- The oracle records the reference result, Relay's initial result, an immediate
  repeat, and any bounded convergence attempts as separate structured
  `differential_outcomes` data.
- Results are classified as `matched`, `converged`, or `divergent`. Converged
  mismatches retain their elapsed time and attempt count, while divergence
  after the configured tolerance makes the CLI fail.
- Reply values, exact Redis errors, and exception behavior are compared. Only
  Relay's internal exception source-location suffix is ignored; same-call key
  names and script digests are kept semantic. Exact observations and bounded
  argument summaries remain available for diagnosis.
- Reference-unsupported methods, nondeterministic random-member reads, and
  cache-metadata reads are skipped until they have explicit comparators rather
  than being reported as false divergences. Sticky pre-existing client errors
  are cleared before each oracle observation.
- CLI and immutable API configuration expose the mode, tolerance, and polling
  interval; HTTP callers cannot enable or expand them beyond server-owned
  configuration.

This slice deliberately does not flush the process-wide cache before every
read: that would destroy pending invalidation state and hide the timing race the
tolerance is meant to classify. Deterministic populate, forced-cold, mutation,
and post-invalidation scenarios are still required to complete the full matrix
above.

## Priority 2: Model stateful client sequences

### Problem

Transactions, pipelines, watches, listeners, connection state, and mutable
options are currently mixed into a flat random command stream. This can produce
useful chaos, but it gives poor guarantees and can leave the client in an opaque
queued state.

Several recent Relay fixes concern exactly these state transitions:

- Per-node `MULTI` broadcast state.
- Replicas remaining in `MULTI` after `DISCARD`.
- `UNWATCH` bookkeeping in `Relay\Cluster`.
- Inconsistent state after cluster `close()`.
- Listener lifetime and endpoint bookkeeping.
- Replacing allow/ignore patterns.
- Adaptive-cache option introspection.

### Proposed scenarios

Add seeded, named state-machine scenarios with explicit postconditions:

#### Cluster transactions

- `MULTI`, queue writes to keys on several masters, then `EXEC`.
- `MULTI`, queue reads/writes on primary and replica routes, then `DISCARD`.
- `WATCH`, `MULTI`, mutation from another client, then `EXEC`.
- `WATCH`, `UNWATCH`, `MULTI`, `DISCARD`.
- Repeat under each failover, distribution, and multikey-reordering mode.
- After every terminal operation, verify that every involved node accepts an
  ordinary command and that `getMode(true)` is atomic.

#### Connection and listener lifecycle

- Register each listener type, close, reconnect, mutate, and dispatch events.
- Replace and remove callbacks before and after disconnect.
- Exercise standalone and cluster listeners.
- Destroy clients with callbacks registered and force garbage collection.

#### Mutable options

- Repeatedly replace and clear allow/ignore patterns.
- Set an option, read it back, run affected commands, and restore it.
- Toggle cache, tracking, invalidations, compatibility, and throw-on-error.
- Exercise adaptive-cache configuration arrays and invalid values.

Introduce a `STATEFUL` command/scenario category. Transaction-mode operations
should not be represented only as ordinary `READ` commands.

### Implementation status

The first stateful slice is implemented as an explicit seeded scenario layer:

- `RunConfiguration::scenarios` selects named scenarios and the runner shuffles
  their order using the workload seed.
- `transaction-exec`, `transaction-discard`, and
  `watch-unwatch-discard` record each operation and explicit postcondition.
- Terminal operations verify atomic mode; commit verifies both writes and
  discard paths verify that queued writes do not appear.
- Results are emitted as `stateful_outcomes`; failed postconditions fail the
  CLI, while missing client methods are represented as `skipped`.
- Transaction and pipeline commands carry the `STATEFUL` flag and are excluded
  from random fuzzing unless `includeStateful`/`--include-stateful` is enabled.

Listener lifecycle, mutable-option scenarios, mutation-from-a-second-client,
and per-node cluster assertions remain the next stateful iterations.

## Priority 3: Guarantee command and variant breadth

### Problem

The safe default filter currently leaves 178 commands, while a default run
executes only 100 steps. With uniform random selection, approximately 77 unique
commands are expected to run and approximately 101 remain untouched.

The current report identifies selected commands and executed commands, but the
scheduler does not guarantee minimum coverage. Random branches inside a command
also provide no indication of which signatures or option combinations ran.

### Proposed scheduler

Add a deterministic coverage phase before weighted chaos:

- `--cover-each=N` executes every selected command at least `N` times.
- Shuffle the coverage order using the run seed.
- Cover normal and raw operations separately when both exist.
- Continue with the current weighted alias scheduler after the coverage phase.
- Report selected-but-unexecuted commands.
- Report counts by command, client, operation, and named variant.

### Named variants

Commands with substantially different argument shapes should expose named
variants, for example:

- `set/plain`, `set/nx`, `set/xx`, `set/ex`, `set/px`, `set/exat`,
  `set/pxat`, `set/keepttl`.
- `zrank/plain`, `zrank/withscore`.
- `zrange/index`, `zrange/byscore`, `zrange/bylex`, and each option form.
- `scan/no-match`, `scan/match`, `scan/count`, and scan-prefix modes.
- Valid raw syntax, invalid arity, invalid numeric value, and invalid token.

The scheduler should be able to guarantee each variant independently of the
long-running random phase.

## Priority 4: Exercise populated and meaningful state

### Problem

The package contains 22 `ProxyInterface` implementations and the `KeySample`
machinery, but `Fuzzer::run()` never selects a proxy operation. It only selects
normal and raw operations.

With a large generated keyspace and a short run, many reads operate on missing
keys and many collection commands operate on empty state. Those are useful
cases, but they underexercise mutation, non-empty replies, serializer decoding,
cache population, and invalidation.

### Proposed approaches

Implement one or both of:

1. A deterministic preconditioning phase that creates keys of every supported
   type, populated collections, expirations, wrong-type values, and selected
   cache states.
2. A public source-sampling mode that wires `ProxyInterface` and `KeySample`
   into the execution scheduler.

A source client must be read-only from the fuzzer's perspective. Sampled data
should be copied into a separate disposable destination rather than mutating a
valuable source.

Useful state classes to guarantee include:

- Missing, empty, one-element, and multi-element values.
- Existing and absent members/fields.
- Numeric and non-numeric strings.
- Values with and without TTLs.
- Partially cached hashes, sets, lists, and sorted sets.
- Keys changed through a second client so invalidation is required.

## Priority 5: Prevent API, catalog, and flag drift

### Current cacheable-command drift

Relay's `relayCommandTable` currently contains 44 `relayMemCmdInit()` handlers.
The fuzzer marks 37 catalog commands as `CACHED`.

Cacheable Relay commands missing from the fuzzer's `@cached` workload are:

- Present but not marked `CACHED`: `BITPOS`, `PFCOUNT`, `ZCOUNT`, `ZMSCORE`,
  `ZRANK`, `ZREVRANK`, and `ZSCORE`.
- Missing from the catalog: `SDIFFCARD` and `SUNIONCARD`.

`GETWITHMETA` and `HGETWITHMETA` are valid fuzzer-only additions to the cached
set because they share Relay's underlying cached GET/HGET machinery.

Several recently cacheable methods also lack `Attributes\Cached` in the Relay
stubs. Add a CI contract that compares:

- Relay's `relayMemCmdInit()` table.
- `Attributes\Cached` in `relay.stub.php` and `cluster.stub.php`.
- The fuzzer's `Command::CACHED` flags.

### Client API coverage

The command catalog contains 234 entries, of which 229 implement the normal
fuzz interface and 157 implement a raw path. Reflection shows many public Relay
command APIs without catalog entries. Many omissions are intentional admin,
pub/sub, module, or local methods, so maintain a reasoned allowlist rather than
treating every missing method as a defect.

High-value additions include:

- `BITFIELD`.
- `BLPOP` and `BRPOP`; the shared blocking-pop base already exists.
- `SDIFFCARD` and `SUNIONCARD`.
- `LMOVEM` and `BLMOVEM`.
- `HGETDEL`, `HGETEX`, and `HSETEX`.
- Hash field expiration and TTL commands.
- `CLUSTERSCAN`.
- Vector-set commands.
- `XACKDEL`, `XDELEX`, and `XNACK`.
- `DELIFEQ` and other supported Valkey commands.
- `HIMPORT` where a compatible disposable target is available.

Every addition must remain capability-gated by client methods/constants and
server command support.

### Flag contracts

Add tests or generated checks for:

- Cacheable methods and `CACHED`.
- Commands that mutate cached state and `INVALIDATING`.
- Blocking commands and `BLOCKING`.
- Local state transitions and `LOCAL` or `STATEFUL`.
- Exact `CROSSSLOT` membership.
- Admin, flush, raw, and crashing safety categories.

## Priority 6: Repair and broaden argument generation

### Confirmed generator defects

#### Millisecond absolute timestamps

`FuzzConfig::getRandomExpireAt(true)` adds millisecond offsets to a
seconds-resolution `time()` value. `PEXPIREAT` does the same directly. These
timestamps are interpreted as milliseconds since the Unix epoch and therefore
expire in 1970 rather than exercising a future absolute expiration.

Use a millisecond epoch for `PXAT` and `PEXPIREAT`, and add fixed-clock tests so
seeded runs remain reproducible.

#### Member qualifier selection

`FuzzConfig::getRandomMember()` currently uses the integer key returned by
`array_rand()` rather than the selected qualifier value. It can produce members
such as `hash:1:*` instead of the intended `hash:float:*` or `hash:int:*`.

This weakens `HINCRBY`, `HINCRBYFLOAT`, and related hash-member workloads.

#### `INCRBYFLOAT` values

The current expression in `incrbyfloat::by()` evaluates to only `-1` or `1`
because of operator precedence. It does not exercise the intended floating
range.

### Broader deterministic corpus

Add weighted boundary values while continuing to use the seeded execution RNG:

- Empty strings and one-byte strings.
- Binary strings and embedded NUL bytes.
- Valid and invalid UTF-8.
- Numeric strings, leading zeros, signs, exponent notation, and `-0`.
- Integer boundaries and just-outside-boundary strings.
- Small, huge, subnormal, infinite, and NaN floats where the command should
  reject them.
- Duplicate keys, fields, and members.
- Empty and very large collections within configured safety limits.
- Deeply nested arrays, null, booleans, integers, floats, and mixed maps for
  serializers.
- Compressible and incompressible byte sequences.
- Empty, binary, long, and hash-tag-containing prefixes and keys.
- Invalid raw arity, malformed numeric tokens, unknown option tokens, and
  conflicting option combinations.

Make valid, server-error, and PHP-type-error generation explicit modes so their
outcomes are classified correctly.

## Priority 7: Fault-injection campaigns

The library should remain usable without importing a process supervisor, but it
can expose hooks that an external release harness uses to coordinate faults.

Promising cluster campaigns include:

- Primary failure during a readonly and a write command.
- Replica timeout under every distribution and failover mode.
- Per-node read timeout while another node remains healthy.
- `MOVED`, `ASK`, `TRYAGAIN`, `CLUSTERDOWN`, connection reset, and truncated
  reply behavior.
- Slot-map changes during a multikey command.
- Each multikey-reordering mode with same-slot and cross-slot inputs.
- Close/reconnect while nodes have different transaction states.

Promising protocol campaigns include malformed RESP aggregates, invalid lengths,
truncation, unexpected reply types, deeply nested aggregates, and replies that
arrive around a timeout boundary.

Add a hard per-operation watchdog. `maxSeconds` currently limits only the loop
between calls and cannot interrupt a command that hangs inside the client.
Signals and watchdog timeouts must be reported distinctly rather than converted
to ordinary Redis replies.

## Priority 8: Resource and leak observability

For a C extension, a semantically correct reply is not sufficient if the
workload leaks memory, descriptors, connections, or transaction state.

Add optional periodic samples for:

- `memory_get_usage()` and peak usage.
- Relay memory/stat counters.
- Open file descriptors where supported.
- Transferred byte counters.
- Connected endpoint and persistent-link counts where supported.
- Commands per second and latency percentiles.
- Client mode and connection state.

Report start/end values and a bounded time-series or slope. Useful targeted
loops include repeated pattern replacement, forced `CROSSSLOT` errors,
listener replacement, connect/close cycles, serializer/compressor changes, and
transaction aborts.

Keep Valgrind, ASan/UBSan, `rr`, core dumps, and external process supervision in
the release harness. The library should emit enough structured metadata and
reproduction material for those tools to remain effective.

## Priority 9: Improve reproduction and reduction

### Complete client-state capture

The script logger currently restores serializer, compression, prefix, and Relay
compatibility, but it does not fully reproduce:

- Relay cluster failover and distribution modes.
- Per-node read timeout.
- Multikey reordering.
- Cache, tracking, invalidation, throw-on-error, adaptive-cache, and pattern
  settings already present on an application-owned client.
- Other supported options that materially affect the workload.

Snapshot all readable, supported options before execution and emit them in the
reproducer. Record options that cannot be read or restored as explicit metadata.

### Reproducer content

Include:

- Full PHP binary path.
- PHP version and build mode.
- Loaded extension versions and source commits when supplied by the caller.
- Client/topology details.
- All effective fuzzer and client options.
- Concrete operation, client identity, and arguments.
- Outcome summaries as comments.
- A marker for the exact operation that triggered the diagnostic.

### Automated minimization

Add an opt-in reducer that works on a copy of the reproduction script and never
overwrites the original evidence. It should attempt to remove command ranges,
clients, option changes, and arguments while preserving the selected warning,
exception, crash, timeout, or semantic mismatch.

## Proposed implementation sequence

### Iteration 1: Outcome integrity and generator correctness

- Preserve client exceptions and Redis errors.
- Attribute outcomes by client and operation.
- Add client-thrown-exception regression coverage.
- Fix PXAT/PEXPIREAT generation.
- Fix member qualifiers.
- Fix `INCRBYFLOAT` generation.
- Correct current cache flags.

### Iteration 2: Guaranteed and meaningful coverage

- Add cover-each scheduling.
- Report unexecuted commands and operation counts.
- Add deterministic preconditioning.
- Wire or explicitly redesign proxy/source sampling.
- Add catalog/stub/Relay drift checks.

### Iteration 3: Cache differential testing

- Introduce reusable generated cases.
- Add cold/warm/control comparison.
- Add invalidation and wrong-type scenarios.
- Add serializer, compression, option, and cluster matrices.

### Iteration 4: Stateful scenarios

- Add the state-machine/scenario abstraction.
- Implement cluster transaction scenarios.
- Implement connection/listener lifecycle scenarios.
- Implement mutable-option scenarios and postconditions.

### Iteration 5: Release harness integration

- Add fault-coordination hooks and hard watchdogs.
- Add resource sampling.
- Complete reproduction state capture.
- Add safe reproduction minimization.

## Baseline observed during this review

- Catalog commands: 234.
- Normal fuzz implementations: 229.
- Raw implementations: 157.
- Proxy implementations: 22, currently not scheduled by `Fuzzer::run()`.
- Safe default selected commands: 178.
- Server-free PHPUnit suite: 97 tests, 916 assertions, passing.
- PHPStan: clean at level `max`.
- PHP syntax checks: passing for `src`, `tests`, and `bin`.
- Both CLI help commands: passing.
- No Redis server or cluster was contacted during the review.
- No live commands or workload seed were used.
