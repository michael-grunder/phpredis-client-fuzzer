# More Strenuous Relay Cluster Stress Testing

## Status

Draft for discussion.

This document proposes ways to make `phpredis-command-fuzzer` materially more
effective at finding deep Relay and PhpRedis client defects. The emphasis is on
new failure surfaces, coordinated concurrency, state-machine cleanup, liveness,
and correctness under disruption. Merely increasing the number of random
commands is not the goal.

## Current strengths

The current fuzzer already has unusually broad command coverage and several
useful stress features:

- 279 concrete command classes, including normal and raw command paths.
- Seeded command and argument generation with concrete reproduction scripts.
- Multiple client instances, strict and coercive invocation, wrong-type input,
  deliberate cross-slot input, command weighting, and safety flags.
- Random `CLIENT KILL` across cluster primaries and replicas, plus worker signal
  injection through `phpredis-fuzz-killer`.
- Relay cluster failover, distribution, node-read-timeout, and multikey
  reordering options.
- Differential cache observations, cache saturation, Relay statistics, and a
  first set of stateful transaction scenarios.
- An existing `evilresp` proxy which already supports deterministic RESP
  mutation, malicious `MOVED`/`ASK` replies, cluster endpoint rewriting, and
  JSONL reproduction records.

That is a strong broad crash-fuzzing baseline. The principal limitation is that
most activity is still an uncoordinated sequence of independent method calls.

## What the current workload does not stress hard

### Operations are synchronous and uncorrelated

`Fuzzer::run()` executes one synchronous operation at a time. Multiple client
instances increase connection count, but a step still chooses one instance and
waits for it to finish. Separate fuzzer processes can run concurrently, but they
have no explicit roles, barriers, shared schedule, or causal event log.

This makes the current workload good at broad coverage but less likely to hit a
specific interval such as:

- after `MULTI` has succeeded on one cluster node but before it succeeds on the
  next;
- after the first portion of a cross-slot `MSET` has been sent but before all
  replies have been accumulated;
- while a slot map is being invalidated or rebuilt;
- while an invalidation listener is dispatching a callback;
- while one process holds an epoch reader record and another process evicts or
  flushes the corresponding shared cache allocation.

### Disruption is not reproducibly tied to client state

`phpredis-fuzz-killer` chooses actions with `random_int()`, has no schedule seed,
and discovers ordinary client IDs independently from the command stream. Its log
can show that a client was killed near a command, but cannot prove which client
object, node, command phase, transaction state, or cache state was affected.

Worker signals primarily test abrupt process teardown. `CLIENT KILL` does test
reconnect and recovery, but the target and timing are probabilistic.

### Stateful scenarios are shallow and front-loaded

The three current scenarios run once before the random stream and cover clean
transaction commit, discard, and watch/unwatch/discard paths. They do not inject
failures between state transitions or compose transactions with cross-node
commands, broadcast commands, close/reconnect, failover, redirects, timeouts, or
option changes.

This is especially interesting because Relay's cluster command engine contains
a cleanup TODO when starting a transaction on a later node fails after earlier
nodes have entered `MULTI`. Recent Relay fixes also cluster around this area:
per-node transaction state, broadcast bookkeeping, `UNWATCH`, replica cleanup,
cross-slot cleanup, and close/reconnect state.

### Random keys are uniform and values are mostly simple

Keys are chosen uniformly from a fixed generated keyspace. Serialized values
are normally either a string or a one-element array. This underrepresents:

- hot-key contention and invalidation storms;
- cache-line/hash-table collisions and repeated replacement of the same entry;
- serializer recursion, object graphs, references, large nested values, and
  binary data;
- size boundaries in RESP, compression, allocation, and collection growth;
- pathological prefixes and hash tags;
- very large multikey fan-out and order-preservation work.

### Important lifecycle surfaces are normally disabled or absent

Local commands such as `close`, `setOption`, listener registration, and event
dispatch are excluded by default. Client construction always uses
`persistent: false`, and client objects normally live for the whole run.

Relay cluster methods with particularly interesting state or lifetime behavior
that do not currently have catalog workloads include:

- `fullscan()` and its custom generator lifecycle;
- `clusterscan()`;
- persistent cluster connection reuse;
- subscribe/unsubscribe state machines.

When local commands are enabled, `setoption` mutates Relay cluster distribution,
failover, per-node read timeout, multi-key reordering, and availability-zone
options during a run. Availability-zone preference behavior still requires a
cluster with multiple replicas that advertise their zones.

Relay cluster slot-cache flushing and cross-worker invalidation are also
included in the catalog when local commands are enabled.

### Crash-free is necessary but not sufficient

The differential oracle covers cacheable atomic reads, but it deliberately does
not build deterministic populate/mutate/invalidate schedules. A run can remain
crash-free while returning stale data forever, losing a cross-slot subcommand,
permuting an `MGET` result, leaking a connection in `MULTI`, spinning in redirect
or retry logic, or growing shared-memory metadata without bound.

`RelayStatsCollector` currently retains hits, misses, OOM counts, and four
memory fields. Relay exposes substantially more useful information, including
errors, transferred bytes, timing, active request counts, free leases, free
epoch records, endpoint state, and debug-build allocator and epoch details.

### Source anchors for the audit

The observations above are grounded in these current implementation points:

- `src/Fuzzer.php` contains the single-operation selection/execution loop and
  runs `StatefulScenarioRunner` once before that loop.
- `src/Cli/FuzzKillerApplication.php` independently discovers processes and
  server client IDs and uses an unseeded `random_int()`-backed schedule.
- `src/Commands/FuzzConfig.php` uniformly chooses generated keys and mostly
  produces strings or one-element arrays as values.
- `src/ClientFactory.php` constructs all cluster clients with
  `persistent: false`.
- `src/Commands/OptionCommand.php` and the `setoption` command exercise Relay's
  capability-gated cluster-specific runtime options when local commands are enabled.
- Relay's `src/commands.c`, in `relayClusterProcessCommand()`, explicitly notes
  that uncommitted transactions on previously touched nodes still need cleanup
  when starting `MULTI` on a later node fails.
- Relay's `src/cluster.c` owns custom `fullscan` generator object handlers and
  cluster-wide close, listener, transaction, and slot-cache state.
- Relay's `relayStatsMethod()` exposes more shared-cache and debug allocator
  state than `RelayStatsCollector` currently preserves.
- The existing `evilresp` project already proxies real standalone and cluster
  servers, mutates typed RESP replies and redirects deterministically, and
  emits reproduction JSONL.

## Goals

1. Exercise coordinated multi-process shared-cache behavior, not just parallel
   independent command streams.
2. Inject faults at named client state transitions and make the schedule
   replayable.
3. Stress cluster topology discovery, redirection, partial fan-out, failover,
   and recovery as first-class behaviors.
4. Assert useful safety, correctness, resource, and liveness invariants after a
   fault.
5. Preserve the package's library-first design. Process and topology control
   belong in optional CLI adapters; embedded callers must be able to provide
   their own actors and fault hooks.
6. Keep destructive behavior opt-in and impossible to enable through the HTTP
   adapter.

## Proposed design

### 1. Add a durable campaign event journal first

Before adding more nondeterminism, add an append-only JSONL journal suitable for
surviving a worker crash or `SIGKILL`. Each record should be flushed when it is
written in durable mode.

Record at least:

- campaign seed, actor seed, PID, actor ID, client ID, and sequence number;
- event kind: operation-start, operation-end, checkpoint, fault, topology,
  stats, heartbeat, process-exit, or invariant;
- command, normal/raw path, concrete arguments or bounded summaries plus exact
  hashes, generated keys, slots, and slot policy;
- client class, mode, relevant options, and known endpoint/client IDs;
- monotonic start/end timestamps and duration;
- reply, Redis error, warning, exception, and mode after the call;
- fault target and exact observed result;
- cluster topology generation and a digest of the current slot map;
- Relay statistics and process resource samples at configurable intervals.

The existing PHP reproduction script remains valuable for a single sequential
stream. The journal becomes the source for multi-actor and externally disrupted
reproduction.

The killer should accept `--seed` and write the same event format. Its random
stream must be independent from command generation so enabling logging or a new
actor cannot perturb command choices.

### 2. Introduce coordinated actor workloads

Add a library-level campaign runner which coordinates logical actors but does
not itself require a process supervisor. An embedded caller can run actors in
workers of its choice. An optional CLI supervisor may use `pcntl` when present.

Useful actor roles are:

| Actor | Workload |
| --- | --- |
| Hot reader | Relay cache reads against a small Zipfian or fixed hot set |
| Cold reader | Reads a large rotating keyspace to force allocation and eviction |
| Writer | Cache-disabled Relay or PhpRedis writes to the readers' exact keys |
| Invalidator | Delete, unlink, expire, rename, restore, and controlled flush operations |
| Transaction actor | Cross-node `MULTI`/`EXEC`/`DISCARD`/`WATCH` scenarios |
| Fan-out actor | Large mixed-slot `MGET`, `MSET`, `MSETNX`, `DEL`, and `UNLINK` |
| Scanner | Cursor scans, `clusterscan`, and early-abandoned `fullscan` generators |
| Lifecycle actor | Construct, connect, close, destroy, reconnect, and reuse clients |
| Listener actor | Register, replace, remove, and dispatch invalidation callbacks |
| Topology actor | Perform explicitly authorized failover, restart, pause, and reshard actions |

Actors should deliberately share some namespaces. Today independent processes
often happen to collide, but collision should be a named campaign property:

- shared hot set;
- actor-private cold set;
- transaction-only set;
- topology/redirect probes with one key in each real cluster slot range.

Support uniform, fixed-hot-set, and Zipfian key selection. A useful default
stress profile would spend most traffic on a small hot set while continuously
turning over a much larger cold set.

Use barriers for a minority of carefully scheduled races and free-running
actors for the rest. Fully deterministic process interleaving is neither
realistic nor required; the exact observed event order must be retained.

### 3. Turn scenarios into an interleavable state-machine engine

Scenarios should be selectable throughout a run with weights, not only once at
startup. Each scenario step should expose named checkpoints to an optional
`FaultInjector` supplied by the caller.

Conceptually:

```text
scenario-start
  -> before-operation
  -> after-request / before-reply, when a transport adapter supports it
  -> after-operation
  -> before-postcondition
  -> scenario-end
```

The base implementation can support before/after checkpoints. Precise
request/reply checkpoints can be provided by `evilresp` or a control process.

#### Cross-node transaction matrix

Generate transactions containing one or more of:

- commands on one node, every primary, and primaries plus replicas;
- cross-slot split commands;
- broadcast commands such as `UNWATCH`, directed commands, and scan rejection;
- cacheable reads and invalidating writes;
- a command that fails argument validation, returns a Redis error, times out,
  redirects, or loses its socket;
- `EXEC`, `DISCARD`, `close`, object destruction, and exception unwinding.

Inject a fault after each successful queue operation and before each subsequent
node is touched. After every terminal path assert:

- the wrapper reports atomic mode;
- every surviving underlying Redis client connection is not flagged as being
  in `MULTI` in `CLIENT LIST`;
- a fresh command to each involved slot completes and is not unexpectedly
  queued;
- a new transaction can commit on the same object;
- discarded writes did not commit and committed writes have the expected
  per-key outcome;
- no transaction callback or queued reply is shifted to a different command.

This should be the highest-priority new scenario family.

#### Cross-slot partial fan-out matrix

Preseed unique values for an ordered list containing:

- many slots and every primary;
- alternating slots rather than keys grouped by slot;
- duplicate keys in adjacent and distant positions;
- missing keys and wrong-type keys;
- one, two, and hundreds or thousands of keys;
- input orders around allocation boundaries.

Run all four multikey reordering modes and kill, pause, redirect, or time out one
node after earlier subcommands have succeeded.

Check result length and order for reads, per-key truth after writes, cache
invalidation, and continued usability. For partial writes, record an explicit
allowed-outcome model rather than pretending the operation is atomic across
nodes.

#### Close, reconnect, and object lifecycle matrix

Repeatedly:

- close before first use, after a cache hit, after a Redis error, after a
  timeout, in a transaction, and during scan/listener use;
- call close twice;
- destroy objects in atomic and failed state;
- create many objects from permuted seed lists;
- reconnect lazily and verify every node can be reached;
- alternate persistent and non-persistent objects when supported;
- force garbage collection with listener callbacks and generators holding
  references to the cluster object.

Track file descriptors, Redis client connections, persistent links, slot-cache
resources, and PHP memory over repeated churn epochs.

#### Scan and generator lifetime matrix

Add catalog/scenario coverage for `clusterscan()` and `fullscan()`.

Exercise:

- full iteration, no iteration, one iteration then destruction, nested
  iteration, repeated `valid/current/next`, and exception from the loop body;
- cluster close, client kill, redirect, slot-cache invalidation, node failure,
  and object destruction between yields;
- empty batches with scan retry enabled;
- prefixes, binary patterns, type filters, large counts, and invalid cursors;
- a quiescent final scan compared with an independent reference key set.

Redis scan semantics permit duplicates and changes during concurrent mutation,
so exact completeness should only be asserted after writers are quiesced.

#### Listener and callback reentrancy matrix

Recent Relay fixes show that listener lifetime is a fruitful area. Build
scenarios which register callbacks on several cluster objects sharing endpoint
caches, then disconnect or destroy selected objects while other objects produce
invalidations.

Callbacks should be able to:

- return normally, return false, throw, unregister themselves, or replace the
  callback;
- call `dispatchEvents()` recursively;
- issue a command on the same object or another object;
- close or destroy the originating object;
- mutate captured references and trigger PHP garbage collection.

Assert that callbacks are delivered only to live registrations, a callback is
not invoked after removal/destruction, callback exceptions do not corrupt later
dispatch, and all objects remain usable.

#### Runtime option-transition matrix

For local-option stress runs, mutate compatible options in coherent epochs
rather than choosing arbitrary invalid values exclusively. Include cluster
options currently only set during construction:

- failover and distribution mode;
- node read timeout;
- multikey reordering;
- availability zone;
- serializer, compressor, compression level, and prefix;
- cache, tracking, client invalidations, adaptive cache, allow/ignore patterns,
  compatibility, throw-on-error, retry, and backoff settings.

For each transition perform a small probe that verifies `getOption()`, command
usability, expected encoding, and cache isolation. Continue retaining a smaller
invalid-value stream for validation and cleanup paths.

### 4. Make fault injection target the actual connection and phase

Add a control-plane abstraction with capability-specific implementations. Do
not put server administration into the generic `Fuzzer` class.

#### Exact `CLIENT KILL`

For each cluster object, use directed `CLIENT ID` calls to identify its current
server-side connection on each node. A side-channel control client can then kill
the connection associated with a named actor and node rather than a random
normal client.

Useful checkpoints include immediately before a command, after warming a cache
entry, between scenario steps, after queuing on one node, and before a retry.
After reconnect, refresh the recorded server-side ID.

#### Real topology churn

On an explicitly disposable cluster, add controller adapters for:

- `SIGSTOP`/`SIGCONT` of one Redis node;
- primary kill and restart;
- replica kill and restart;
- manual or automatic replica promotion;
- replica pause/lag while read distribution is enabled;
- slot migration/import and reshard while hot keys are active;
- temporary `CLUSTERDOWN`, uncovered slots, and recovery;
- stale seed nodes and permuted/mixed valid and invalid seed lists.

These actions exercise real slot-map generations, health-check backoff,
failover selection, replica `READONLY` state, and MOVED/ASK handling in ways
that `CLIENT KILL` cannot.

Every topology action must record before/after `CLUSTER NODES`, `CLUSTER SLOTS`
or `CLUSTER SHARDS`, target process identity, and convergence time.

#### Integrate the existing `evilresp` proxy

Do not build a second RESP mutation engine. Add a documented campaign adapter
which configures the existing `evilresp` instance with the campaign seed,
filters, RESP mode, mutation probability, topology probability, and repro file.

The fuzzer and proxy journals should share a campaign ID so a mutated reply can
be joined to the exact client operation. `DEBUG EVIL MODE RESET` can delimit
reproduction epochs.

Start with two profiles:

- valid RESP plus malicious but well-formed MOVED/ASK topology;
- malformed/overflow RESP on a narrow command filter.

Extend `evilresp`, rather than this PHP package, with transport mutations it
does not currently expose:

- write replies one byte or a few bytes at a time;
- delay before the first byte or between frames;
- close at every byte offset in a reply;
- send a valid prefix and then stall;
- truncate bulk, aggregate, and error replies;
- inject RESP3 push invalidations between ordinary reply frames;
- reset the connection after accepting only part of a request.

Transport behavior should be seeded and included in its existing repro JSONL.
Recent PhpRedis fixes for short and malformed replies reinforce the value of
running both clients through this layer.

### 5. Add boundary-biased argument generation

Keep the normal generator, but allow a configurable portion of values to come
from a boundary corpus. Useful boundaries include:

- lengths 0, 1, 2, 7, 8, 15, 16, 31, 32, 63, 64, 127, 128, 255, 256,
  1 KiB minus/equal/plus one, and analogous larger sizes;
- empty strings, embedded NUL, CRLF, high bytes, invalid UTF-8, and repeated
  compressible bytes;
- incompressible seeded bytes;
- integer and float minima/maxima, numeric strings, infinities, negative zero,
  NaN where accepted by PHP parsing, and just-out-of-range values;
- empty, deeply nested, wide, referenced, repeated-reference, and object values
  for serializers which support them;
- prefixes and keys containing empty/multiple/unclosed hash tags;
- large duplicate-heavy multikey arrays and maps.

Large cases must have independent byte and element budgets. They should be rare
enough that the campaign still makes progress, and their exact bytes must be
reconstructible from the seed or retained as artifacts.

Use a boundary distribution rather than sampling uniformly between 1 and an
enormous maximum. Uniform sampling almost never hits the small transition
points where integer width, small-buffer, and allocation strategies change.

### 6. Add correctness and recovery oracles

#### Deterministic cache invalidation oracle

Create a small versioned register workload:

1. An uncached writer stores a unique monotonically versioned value.
2. Relay readers populate and repeatedly read the key.
3. The writer changes, expires, deletes, restores, or renames it.
4. Readers are polled until they converge within a configured tolerance.

Record the initial stale observation, convergence delay, invalidation event,
and final value. Run the same workload across processes to exercise shared
cache invalidation, not only two objects in one call stack.

For failover/distributed replica reads, use a topology-aware weaker oracle:
bounded eventual convergence and membership in the set of values actually
committed. Do not label legitimate replica lag as corruption.

#### Multikey assembly oracle

Unique values tied to input position make it straightforward to catch omitted,
duplicated, or shifted replies. Include duplicate keys because correct result
assembly must preserve input multiplicity even when wire requests are grouped
or reordered by slot.

#### Transaction cleanup oracle

In addition to `getMode(true)`, inspect server-side client flags and run a fresh
probe transaction. Wrapper mode alone can look atomic while one node's socket
is still in `MULTI`.

#### Slot-map convergence oracle

After a real reshard or injected redirection, repeatedly address keys from the
moved slot and record:

- redirect count and chain length;
- slot-cache generation changes;
- time to reach the correct node;
- whether unrelated slots remain usable;
- whether a stale or malicious redirect can permanently poison the map.

#### Progress oracle

Each actor emits a heartbeat independent of final result generation. Detect:

- no completed operation within a deadline;
- endless redirect/retry loops;
- repeated reconnect without forward progress;
- unexpectedly long time in a nominally bounded scenario;
- all workers blocked on the same node.

A liveness failure should retain the worker PIDs and allow the outer harness to
capture stacks, cores, `rr`, or debugger state before terminating anything.

### 7. Expand resource and shared-cache telemetry

Sample and retain deltas or time series for:

- Relay stats: requests, errors, filtered, bytes, timing, active/max requests,
  free leases, free epoch records, endpoint data, memory, and OOM;
- debug Relay allocator freelists and pending epoch reclamation when present;
- PHP memory and peak memory;
- process RSS, virtual memory, thread count, and open file descriptors from
  `/proc` where available;
- per-node `CLIENT LIST` counts and flags;
- persistent link and slot-cache debug data when the loaded Relay build exposes
  it;
- operation latency histograms by command, outcome, actor, node, and fault.

Run campaigns in epochs: active workload, quiescence, explicit garbage
collection, then another sample. Flag monotonic growth across several quiescent
epochs rather than treating a single peak as a leak.

Debug-only fields must be discovered with capability checks so production Relay
builds and PhpRedis-only consumers remain loadable.

### 8. Exercise construction, transport, and request lifecycle variants

Add a pairwise campaign matrix instead of relying only on one random value for
each setting:

- serializer and compression combinations;
- cache/tracking/client-invalidation combinations;
- failover, distribution, multikey reorder, and availability-zone modes;
- short and long connect/read/node-read timeouts;
- persistent and non-persistent connections;
- seed order, duplicate seeds, dead seeds, hostnames, IPv4, IPv6, TLS contexts,
  and authentication forms;
- compatibility and throw-on-error modes;
- normal and coercive invocation.

Pairwise generation provides much better interaction coverage than testing the
full Cartesian product or independently choosing one value per long run.

For persistent connections, a useful CLI harness should execute many short PHP
request lifecycles, not only create a persistent connection in a single long
CLI request. Include clean and abrupt request shutdown, inherited state after
`fork()` where supported, and reuse after credentials/options change.

### 9. Optional coverage feedback

When Relay or PhpRedis is built with sanitizer coverage or another lightweight
edge counter, record coverage digests by campaign profile. Use the feedback to
identify scenario and option combinations which reach new native paths.

This should not replace seeded workload selection with an opaque random source.
A selected corpus item must still retain its campaign seed, inputs, topology,
and fault schedule.

At minimum, use observable path proxies when native coverage is unavailable:
cache hit/miss, reconnect, redirection, failover node chosen, slot-cache
generation, transaction node count, fan-out width, retry count, listener
dispatch, eviction/OOM, and exception class.

## Recommended implementation order

### Phase 1: observability and high-value state cleanup

1. Add the durable campaign journal and seeded killer schedule.
2. Make stateful scenarios interleavable and add named checkpoints.
3. Implement the cross-node transaction failure matrix and its server-side
   cleanup oracle.
4. Implement the large cross-slot partial fan-out matrix.
5. Expand Relay statistics and add a progress watchdog.

This phase targets the same transaction/fan-out code that has produced recent
defects and requires no cluster administration.

### Phase 2: coordinated cache and lifecycle pressure

1. Add role-based multi-actor campaigns with shared hot and cold namespaces.
2. Add deterministic cache invalidation and multikey assembly oracles.
3. Add client object churn, persistent connection variants, listeners, and
   runtime option transitions.
4. Add `clusterscan` and `fullscan` generator scenarios.
5. Add boundary-biased values and multikey sizes.

### Phase 3: hostile transport and topology

1. Add the first-class `evilresp` campaign adapter.
2. Add deterministic short-write, truncation, stall, and close-offset modes to
   `evilresp`.
3. Add exact connection targeting by server-side client ID.
4. Add a disposable-cluster controller for stop/continue, restart, promotion,
   lag, and reshard.
5. Add slot-map convergence and liveness oracles.

### Phase 4: campaign optimization

1. Add pairwise configuration generation.
2. Add native coverage feedback where builds support it.
3. Add automatic reduction of actor count, scenario steps, fan-out width, fault
   events, and argument sizes while preserving a failure.

## Safety model

The generic fuzzer must remain safe-by-default relative to its intentionally
mutating workload.

- Actor coordination, journaling, boundary values, exact client kills, and
  malformed proxy replies each get separate opt-in capabilities.
- Server process control, failover, resharding, flushes, pauses, and broad
  client kills require an explicit disposable-target acknowledgement in the CLI.
- Topology control must use an allowlist of exact node addresses and resolved
  process identities. It must never infer permission from the cluster's ability
  to answer `CLUSTER NODES`.
- Destructive controls are not exposed by `FuzzerEndpoint` and cannot be enabled
  by request-controlled options.
- The library accepts user-supplied fault/topology adapters but does not spawn or
  administer infrastructure implicitly.
- Reproducer scripts, campaign journals, proxy journals, cores, sanitizer logs,
  Valgrind output, `rr` traces, and topology snapshots are never overwritten or
  deleted automatically.

## Success criteria

A more strenuous campaign is successful when it can demonstrate all of the
following, not merely run more commands per second:

- named transaction and cross-slot phases were faulted on every participating
  node;
- several Relay processes concurrently hit, invalidate, evict, and repopulate
  the same shared-cache entries;
- real and malicious redirects, node timeouts, reconnects, and slot-map changes
  occurred and converged;
- every injected fault has a durable causal record tied to an operation;
- transaction, cache, ordering, slot-map, and liveness invariants were checked;
- file descriptor, connection, PHP memory, Relay shared-memory, lease, and epoch
  behavior were sampled across quiescent epochs;
- a failure can be replayed from retained commands, actor seeds, observed event
  order, topology snapshots, and `evilresp` mutations;
- all dangerous capabilities remained disabled unless explicitly selected for a
  disposable target.

## Initial recommendation

Start with the cross-node transaction failure matrix, durable event journal,
and coordinated hot-reader/uncached-writer actors. Those changes are relatively
contained, directly target recent Relay defect classes, and add strong evidence
for failures that currently appear only as a rare crash or an unexplained
exception.

Integrating `evilresp` should follow immediately after that foundation. It
already supplies most of the deterministic hostile-protocol and malicious
topology machinery, so the remaining work is primarily campaign coordination,
transport-level fault modes, and correlation of its JSONL records with the
fuzzer journal.
