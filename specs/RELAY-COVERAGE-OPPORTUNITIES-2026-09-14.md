# Relay coverage opportunities — 2026-09-14

This review identifies ways to reach currently unexecuted Relay code, using the
supplied gcovr snapshot and the current fuzzer and extension sources. The best
first experiments are **deliberate cache warming, the existing adaptive-cache
hook, and Relay's existing Table tests**. More random command volume alone will
not reach several of the largest gaps.

These are proposed experiments, not measured coverage improvements. No live
Redis workload was run during this review. Finding IDs are stable so we can
refer to them while iterating.

## Evidence and baseline

- [HTML report](/tmp/relay-coverage.html), generated at 12:07 on September 14.
- [Function report](/tmp/relay-coverage.functions.html), including call counts.
- `/tmp/relay-coverage-summary.json` and `/tmp/relay-coverage.txt`, timestamped
  12:04. All 46 per-file line, function, and branch totals in the JSON were
  checked against the later HTML and match.
- Relay source: `/home/mike/dev/relay-dev/relay`, also reached through
  `/home/mike/dev/phpfarm/src/php-8.5.0-debug/ext/relay`.
  HEAD: `c134fe47d6f3797c8890e8c203a2e452e97dd856`.
- Fuzzer HEAD: `ae0d98651967653658209685fe005e9efe295e2e`.
  These are working-tree reviews: local changes exist, so HEAD alone does not
  identify the reviewed sources or the binary that produced the report.

| Metric | Covered / total | Coverage |
| --- | ---: | ---: |
| Lines | 17,503 / 29,667 | 59.0% |
| Functions | 1,797 / 2,674 | 67.2% |
| Branches | 11,709 / 33,843 | 34.6% |

Largest relevant pools of uncovered lines:

| Source | Covered / total lines | Missing lines | Branch coverage |
| --- | ---: | ---: | ---: |
| `commands.c` | 5,609 / 9,942 | 4,333 | 34.2% |
| `relay.c` | 2,714 / 4,088 | 1,374 | 40.8% |
| `table.c` | 65 / 1,196 | 1,131 | 1.4% |
| `cluster.c` | 1,531 / 2,181 | 650 | 34.8% |
| `keys.c` | 701 / 1,300 | 599 | 39.2% |
| `heartbeat.c` | 48 / 604 | 556 | 10.2% |
| `cache.c` | 1,106 / 1,625 | 519 | 51.3% |
| `session.c` | 11 / 417 | 406 | 0.0% |
| `hyperloglog.c` | 5 / 320 | 315 | 0.6% |
| `log.c` | 25 / 277 | 252 | 7.8% |
| `sentinel.c` | 4 / 250 | 246 | 0.0% |
| `cms.c` | 9 / 216 | 207 | 1.2% |
| `reader.c` | 17 / 210 | 193 | 1.7% |
| `adaptivecache.c` | 34 / 118 | 84 | 2.1% |

These are opportunity pools, not predictions of attainable gains. Some code
needs different hardware, a different build, or an explicit fault condition.
Several experiments below overlap in the same files.

## What the current launcher suggests

[fuzz-coverage.sh](fuzz-coverage.sh) currently selects `redis,relay`, a five-second
coercive workload, raw and raw-chaos operations, local/stateful/connection/flush
categories, two children, and fast cache saturation. Its comment says “Direct
disposable cluster,” but the active client selection is standalone. The supplied
coverage nevertheless contains cluster execution, so it must not be attributed
solely to this launcher as currently written.

Three concrete limitations of this configuration:

1. [Fuzzer.php](src/Fuzzer.php) randomly chooses among normal, raw, and raw-chaos
   operations when all are eligible. Adding those modes divides opportunities
   to execute valid normal-method paths; it does not make every step do all three.
2. [CacheSaturator.php](src/CacheSaturator.php) fast mode fills dedicated strings
   using `SETBIT`/`GET`. Seeded mode instead populates strings, lists, sets,
   hashes, and sorted sets. Neither mode currently warms HLL payloads.
3. [fuzz.ini](fuzz.ini) does not enable sessions, logging, or the adaptive hook.
   The launcher includes the no-msgpack guard. That intentional guard should be
   retained; a dedicated serialization experiment can be discussed separately.

Use a valid-method campaign with stable settings alongside the existing coercion
and fault campaigns. Record each campaign's contribution separately.

## Prioritized findings

### F01 — Warm HLL payloads before PFCOUNT (first iteration)

**Evidence:** `hyperloglog.c` has only initialization coverage. `relayHllCount`,
`relayHllMerge`, and `relayHllAggregateCount` were never called. In contrast,
`relayPFCountGet` was called 1,081 times and has 100% line coverage: entering the
dispatcher is not evidence that its HLL implementation ran.

**Cause:** [commands.c:4184](/home/mike/dev/relay-dev/relay/src/commands.c:4184)
requires a complete cached string before calling the HLL implementation.
`PFCOUNT` only returns cardinality and cannot itself populate that string.
The fuzzer assigns `PFADD`/`PFCOUNT` the `hll` key type and `GET` the `string`
type. [FuzzConfig.php](src/Commands/FuzzConfig.php) puts these into different
key names. Accidental wrong-type traffic is not a reliable warming strategy.

**Experiment:** Add a bounded HLL scenario: `PFADD` → Relay `GET` on the exact
HLL key → `PFCOUNT`, then repeat with two warmed keys and a known-missing key.
Use serializer/compression `none` initially. Include sparse and dense payloads,
then mutate with `PFADD`/`PFMERGE`, warm again, and count again. Warm once before
any server `PFCOUNT` as well as after it, to exercise uncached and cached
cardinality in the HLL header. Keep cluster keys in one hash slot.

**Existing starting point:**
[CacheTest.php:514](/home/mike/dev/relay-dev/relay/tests/Instance/CacheTest.php:514)
already implements most of this sequence, including malformed cached strings.
Run that test with the coverage extension before writing a second implementation.

**Success criterion:** HLL count/merge functions become nonzero; cache-hit
counters confirm the count was computed locally; results agree with the server.
The 315 missing HLL lines include alternative CPU implementations, so full file
coverage is not a reasonable single-machine target.

### F02 — Deliberately reach complete and partial collection caches (first iteration)

**Evidence:** Zero-call functions include `relayUnpackMapEntry`,
`relayMergeHashMems`, `relayTreatListRange`, `relayCmpSetsBySizeAsc/Dec`,
`relayZrangeToZVal`, and `relayZSetModeCompatible`. `relayZrangeGet` was entered
4,173 times; `relaySetInterDiff` 3,786 times, but only 25.9% of its lines ran.
This is consistent with repeated early cache misses or rejected conditions.

**Experiment:** Add short setup/read/read/mutate/read scenarios, with stable
prefix, serializer, cache options, and key identity during each sequence:

| Fixture | Warm-up and targeted reads | Branches to vary |
| --- | --- | --- |
| String | `SET`, `GET`, then `GETRANGE`, `BITCOUNT`, `BITPOS` | Empty, binary, and longer payloads; negative ranges; bit/byte offsets |
| Hash | `HSET`, selected `HGET`/`HMGET`, then `HGETALL`, `HKEYS`, `HVALS`, `HRANDFIELD` | Partial → complete; present/missing fields; with-values and count forms |
| List | `RPUSH`, partial then full `LRANGE`, `LINDEX`, `LLEN` | Negative indices, boundaries, empty and out-of-range results |
| Set | Populate two unequal-size sets, warm **both** with `SMEMBERS`, then `SINTER`, `SUNION`, `SDIFF`, `SRANDMEMBER` | Disjoint/overlapping/identical inputs; positive/negative counts |
| Sorted set | `ZADD`, complete `ZRANGE`, then rank/score/lex reads | REV, WITHSCORES, equal scores, inclusive/exclusive bounds, missing members |

For sorted sets, use a separate equal-score fixture for lexicographic reads.
[commands.c:9140](/home/mike/dev/relay-dev/relay/src/commands.c:9140) deliberately
bypasses the cache for `LIMIT`, and complete score/rank snapshots do not substitute
for lex snapshots. Include those as intentional misses rather than expecting hits.

**Existing support:** Seeded saturation, `keySkew`, and `keyReadGroups` can improve
warm-up and reuse. They do not replace an ordered scenario that proves the same
keys were populated and read. Relay's
[CacheTest.php](/home/mike/dev/relay-dev/relay/tests/Instance/CacheTest.php)
already has focused tests for these cache families.

**Success criterion:** Selected zero-call helpers execute, hit/miss counters match
the intended sequence, and values remain correct after mutation and invalidation.
Then repeat a small matrix of serializers, compressors, prefixes, and queued modes.

### F03 — Enable adaptive caching and exercise the returned object (first iteration)

**Evidence:** `relaySetAdaptiveCacheOptions`, `relayCmsCreate`, the sketch counting
paths, and the `AdaptiveCache` methods `stats`, `incrBy`, `loadFactor`, `flush`, and
`lastFlush` are uncalled. The 216-line internal `cms.c` is **not** the RedisBloom
`CMS.*` command implementation; it powers Relay's adaptive cache.

**Existing support:** [hooks/adaptive.php](hooks/adaptive.php) already enables
`OPT_ADAPTIVE_CACHE` with validated options and reproduction logging. It is absent
from the current coverage launcher. Random `setOption` selection does not include
this option. Start by adding the existing hook to a separate focused campaign.

**Experiment:** Use a small sketch and a fixed small key set. Move individual
keys from below to above `minEvents` and the read/write threshold; run both `pure`
and `scaled` formulas. Read `$client->adaptiveCache->stats($key)` and `loadFactor()`.
Exercise synchronous/asynchronous `flush`, progress after flushing, and
`lastFlush`. Compare two clients attached to the same endpoint, including one
with the adaptive policy disabled. Separately test reconfiguration conflicts and
invalid option shapes through a recorded call path, since the hook correctly
rejects malformed configuration before Relay sees it.

**Source:** [relay.c:7552](/home/mike/dev/relay-dev/relay/src/relay.c:7552),
[adaptivecache.c:127](/home/mike/dev/relay-dev/relay/src/adaptivecache.c:127), and
[cache.c:2215](/home/mike/dev/relay-dev/relay/src/cache.c:2215).

**Success criterion:** Verify `adaptiveCache->status` becomes active, sketch
counters change, and reads cross the admission threshold. Restart between
dimension experiments: existing shared sketches cannot simply be resized.
This reaches 291 missing lines across `cms.c`/`adaptivecache.c`, plus related
cache/cluster/option code, subject to CPU dispatch and failure-only branches.

### F04 — Exercise Relay Table directly (large, independent opportunity)

**Evidence:** `table.c` has 1,131 missing lines and 59 uncalled functions.
The main fuzzer accepts the four Redis-compatible client classes, not
`Relay\Table`. The catalog's `fullscan` does not call `Table::fullscan`.

**Experiment:** Start with Relay's existing
[TableTest.php](/home/mike/dev/relay-dev/relay/tests/Unit/TableTest.php).
Then add a separate seeded Table driver using the existing value corpus:
`set/get/getWithMeta`, exists/delete, TTL/expiry, namespaces, enumeration,
memory/stats, and fullscan generator exhaustion and early destruction.
Exercise `recordMetrics/getMetrics` with consistent schemas, conflicting
schemas, bucket boundaries, retention rollover, and all aggregation operators.

Use a fresh process and explicit small memory budget. Add a separate fork test
for genuinely shared mutations and namespace deletion during scans. Retain an
operation log and a simple expected-value model. A static Table API needs its own
recording adapter; do not force it into the four-client command interface.

**Success criterion:** Table operations and metrics helpers become nonzero,
with returned values, TTL behavior, and generator lifecycle checked. Existing
Table tests are predominantly local, but audit their bootstrap and selected
cases before treating a whole extension test-suite invocation as server-free.

### F05 — Build nonblocking stream consumer-group sequences

**Evidence:** `relayXReadRCmd`, `relayXReadgroupRCmd`, `relayXReadFmt`,
`relayXClaimFmt`, and `relayXAutoClaimFmt` have zero calls. XCLAIM/XAUTOCLAIM
argument builders did execute, suggesting their successful reply paths were
not reached. Both fuzzer [xread](src/Commands/Command/xread.php) and
[xreadgroup](src/Commands/Command/xreadgroup.php) have the `BLOCKING` flag,
even though their APIs also have nonblocking forms. The current launcher does
not enable that category.

**Experiment:** Create a new explicitly nonblocking scenario: add known entries,
create a group at `0-0`, read new entries with `>`, inspect pending messages,
claim recorded IDs with minimum idle zero, auto-claim from `0-0`, and acknowledge.
Exercise JUSTID and full messages, deleted pending entries, empty results, and
multiple streams sharing a cluster hash tag. Use the actual IDs returned by XADD.

**Success criterion:** Nonempty read/claim reply formatters execute, followed by
verified group/pending state. Preserve the existing `BLOCKING` flags; do not
enable the entire blocking category just to get the nonblocking forms.

### F06 — Add JSON, search, and server CMS workloads where supported

**Evidence:** The JSON and FT argument builders and reply formatters are entirely
uncalled, including `relayJsonSetRCmd`, `relayJsonGetRCmd`, `relayJsonReplyFmt`,
`relayFtCreateRCmd`, `relayFtSearchRCmd`, and `relayFtAggregateRCmd`. Relay's
`cmsInitByDim/cmsInitByProb/cmsIncrBy/cmsQuery/cmsMerge/cmsInfo` wrappers also have
zero calls. The current catalog lacks these command families.

**Experiment:** Reuse the existing
[JsonTest.php](/home/mike/dev/relay-dev/relay/tests/Commands/JsonTest.php) and
[FtSearchTest.php](/home/mike/dev/relay-dev/relay/tests/Commands/FtSearchTest.php)
on a disposable server with the required commands. Add small fixture-backed
catalog families: JSON documents with matching/missing paths and array edits;
search indexes with documents, queries, aggregation, and cursors; server CMS
initialization, increments, queries, and merge.

Check both client methods and server capabilities. Builders may run against an
unsupported server and still only produce unknown-command replies; that will
not exercise successful reply formatting. Keep index/config administration
explicitly scoped to the fixture rather than opening general admin access.

**Success criterion:** Valid nonempty and empty replies reach their formatters;
then mutate individual options and reply shapes. This is a major contributor
to the `commands.c` opportunity, but its exact gain needs a separate run.

### F07 — Use the existing subscription driver, then broaden callback state

**Evidence:** Subscribe/psubscribe/ssubscribe wrappers and callback dispatch,
unsubscribe, and resubscribe helpers are uncalled. The main catalog lacks
PUBLISH/PUBSUB, and subscriptions are intentionally ignored by its server-command
coverage list.

**Existing support:** [SubscribeApplication.php](src/Cli/SubscribeApplication.php)
and `bin/phpredis-fuzz-subscribe` already provide a separate workload, including
Relay callback re-entry and a `--no-fork` mode. Start with a bounded run of that
driver on the coverage binary. Use an outer timeout: the forked path sends on
a timing loop rather than waiting for a subscription-ready handshake.

**Next extension:** Add acknowledged publisher/subscriber synchronization,
pattern and shard subscriptions, callback replacement, empty unsubscribe,
connection loss/re-subscription, and both compatibility modes. Relay's
[PubSubTest.php](/home/mike/dev/relay-dev/relay/tests/Commands/PubSubTest.php)
is another starting point. Record the whole subscription sequence: current
batch script logs replay catalog calls outside the subscription callback.

**Success criterion:** Actual messages invoke callbacks; confirmation/reply
queues remain aligned after nested operations and reconnects.

### F08 — Use request boundaries to reach the custom invalidation reader

**Evidence:** `reader.c` is 8.1% covered; `relayReaderNext`, `relayReaderFeed`,
`relayReaderReadHeader`, `relayReaderReadBulk`, and `relayReaderInvalidationsPoll`
are uncalled. This is not simply the ordinary Redis command reply parser.

**Cause:** [relay.c:3412](/home/mike/dev/relay-dev/relay/src/relay.c:3412)
allows SIGIO handling only when no PHP request is active. RSHUTDOWN installs
the connection handlers; RINIT removes them. A CLI script remains in one request,
including while it sleeps.

**Experiment:** Use an isolated local FPM pool with persistent Relay connections.
Request A warms keys and finishes; an external writer mutates them while the
worker is idle; request B verifies fresh values. Start with one worker for
deterministic reuse, then introduce multiple workers. Add fragmented invalidation
messages and disconnects during the idle interval through a dedicated local proxy.
Gracefully stop the pool to retain coverage output.

**Success criterion:** SIGIO reader counters become nonzero, partial messages
are handled across reads, and request B cannot reuse invalidated cached values.
The generic HTTP adapter alone does not guarantee the necessary worker lifecycle.

### F09 — Make invalidation callbacks mutate their own lifecycle

**Evidence:** `relayInvalidationsDispatch` already has 99,109 calls and 87.5%
line coverage. This feature is not absent. However, `relayRemoveListenerZObj`,
`relayRemoveListenerZVal`, and `relayFcallContextDtor` are uncalled. The current
listen/onInvalidated/onFlushed catalog callbacks mostly return trivial values.

**Experiment:** Warm a key and invalidate it from a peer client; in the callback,
remove/replace itself, add another listener, issue nested commands, dispatch again,
throw an exception, or close the owning client. Include object/closure reference
cycles followed by explicit GC. Verify which callbacks execute for the current
event versus the next one.

**Existing starting point:**
[EventTest.php:210](/home/mike/dev/relay-dev/relay/tests/Instance/EventTest.php:210)
already covers self-replacement, callback exceptions, closing the listener,
and nested dispatch. The cluster event suite should accompany it.

**Success criterion:** Listener removal and ownership cleanup execute, with
correct event delivery and no stale callback or retained owner.

### F10 — Vary connection construction and actual cluster topology

**Evidence:** TLS creation/negotiation functions are uncalled, as are public
`pconnect`, `relayClusterLoadIniSettings`, `relayClusterInitSlots`, its slot-reply
validators, `randomOtherReplica`, and several retry backoff implementations.
Changing an option alone does not necessarily exercise the behavior it controls.

**Cause:** [ClientFactory.php](src/ClientFactory.php) calls `connect` with a fixed
argument shape and constructs clusters with a null name and explicit seeds.
It does not exercise named INI clusters or TLS certificate contexts. Relay can
still use persistent connections through its default setting, so zero public
`pconnect` calls do not establish that all persistence code is untested.

**Experiments:**

- Pass application-owned clients built with supported constructor/options forms,
  explicit `pconnect`, Unix sockets, ACL/password authentication, and TLS contexts.
  Test successful TLS before certificate/hostname failures and reconnects.
- Use named clusters from INI as well as seed lists. A Redis **older than 7.0**
  selects `CLUSTER SLOTS`; the current source selects `CLUSTER SHARDS` at 7.0+
  ([cluster.c:2700](/home/mike/dev/relay-dev/relay/src/cluster.c:2700)). Repeating
  modern-server runs will not enter the old slot parser.
- Use a cluster with replicas, then coordinate a node outage/recovery and a
  bounded slot migration to exercise retry, MOVED/ASK, and redistribution paths.
  Separate these infrastructure scenarios from generic command fuzzing.
- Warm, invalidate, and exhaust the slot-cache lifetime. Vary cross-slot requests
  only on client-split DEL/MGET/MSET/MSETNX/UNLINK; keep other related keys co-located.

**Success criterion:** Identify the chosen parser, replica, retry algorithm, and
reconnect path through coverage and observations. Record the actual server
versions and topology; current launcher comments are insufficient evidence.

### F11 — Exercise the PHP session handler as a separate workload

**Evidence:** `session.c` has 406 missing lines and zero covered branches.
Normal client method calls do not invoke PHP's registered session handler.

**Experiment:** Use a build with session support and explicit
`session.save_handler=relay` / disposable `session.save_path`. Exercise start,
write-close, reopen/read, lazy timestamp updates, strict-ID validation,
regeneration, destroy, missing/expired sessions, compression, and weighted
session endpoints. Add two processes contending for one known session ID, with
bounded locking retries and lock expiry.

[session.c:74](/home/mike/dev/relay-dev/relay/src/session.c:74) chooses different
lock-release commands for Redis 8.4+, Valkey 9+, and older servers. Include those
server families only when intentionally testing those branches.

**Success criterion:** Session contents survive round trips, expiry and lock
ownership behave correctly, and session callbacks become covered. Record INI and
request/process order, which a list of Redis commands alone cannot reproduce.

### F12 — Exercise Sentinel through its own client

**Evidence:** `sentinel.c` has four covered lines, one covered function, and no
covered branches. `Relay\Sentinel` is outside the fuzzer's four-client union.

**Experiment:** Start with
[SentinelTest.php](/home/mike/dev/relay-dev/relay/tests/Sentinel/SentinelTest.php)
on an explicitly selected disposable Sentinel deployment. Cover constructor
forms, discovery, primary/replica lookup, missing names, and error handling.
Then coordinate loss of the initially selected Sentinel to exercise discovery
and reconnect. Forced failover/config changes belong in a separate fixture.

**Success criterion:** Discovery and reply formatting execute and continue to
return the expected topology after a controlled failure.

### F13 — Add a small startup/logging matrix; isolate heartbeat testing

**Evidence:** `log.c` has 252 missing lines; `heartbeat.c` 556. `relayOpenLogFile`,
`relayLogMemLine`, `relayLogSignalLine`, heartbeat HTTP/JSON parsing, and telemetry
builders are uncalled. [fuzz.ini](fuzz.ini) leaves logging and prefault overrides
commented out.

**Low-effort experiment:** Use separate short processes with a unique logfile,
supported log levels, and `relay.prefault=off/populate/touch` where supported.
Exercise disabled cache, a small enabled cache, and bounded allocation/eviction
pressure. Confirm expected startup/shutdown messages and coverage deltas.

**Dedicated heartbeat experiment:** First verify build gates. The endpoint and
timing environment overrides in
[heartbeat.c:187](/home/mike/dev/relay-dev/relay/src/heartbeat.c:187) are guarded
by `RELAY_DEBUG`; unlimited-memory builds also gate heartbeat activation in
[relay.c:2941](/home/mike/dev/relay-dev/relay/src/relay.c:2941).
Use a local mock endpoint with synthetic responses and explicit scheduling to
exercise success, malformed HTTP/JSON, missing fields, failure, and recovery.
Keep test telemetry local. Do not just enable the normal remote heartbeat and
hope that longer runs cover it. Some licensing states may require a dedicated
test fixture rather than externally valid credentials.

**Success criterion:** Selected logging/parser branches execute with predictable
outputs. This is lower priority than cache correctness, Table, and stream replies.

## Measurement and implementation plan

1. Preserve this baseline and its matching binary/build inputs before collecting
   new counters. Do not delete existing `.gcda`, HTML, reproducer, or trace files.
   Use separate coverage output/build directories for new campaigns and merge
   only matching-build results. Record abnormal exits: crashes and forced kills
   can lose coverage counters.
2. Run selected existing Relay tests for F01/F02/F04/F09, then the F03 hook and
   F07 driver, each with the exact coverage extension loaded. This distinguishes
   missing test cases from existing tests absent from this measurement.
3. For new scenarios, start with seed `20260914`, a small fixed fixture, valid
   normal methods, and explicit cache settings. Record setup, concrete keys and
   values, client options, peer operations, returned IDs, and teardown. Add raw,
   coercion, serializer, and topology variants after verifying the success path.
4. Keep fuzzer additions library-friendly. Shared command work must retain all
   four clients; Relay-only auxiliary objects should be capability-gated and use
   separate adapters where necessary. Keep command execution and diagnostics
   inside the existing recorder/Command paths. Retain safety gates.
5. Diff function entry and branch coverage per experiment. Report newly executed
   functions, new lines/branches, cache hits/misses, successful replies, expected
   Redis errors, PHP warnings, exceptions, and runtime. A function's being entered
   is insufficient if its meaningful body still exits early.

| Iteration | First deliverable | Why first |
| --- | --- | --- |
| A | F01 HLL and F02 ordered collection cache scenarios | Direct evidence of missing prerequisites; existing test recipes |
| B | F03 adaptive hook campaign and auxiliary-object calls | Existing enablement hook; several nearly cold files |
| C | F04 Table suite/driver and F05 stream success scenarios | Large independent API gap plus blocked successful replies |
| D | F07 subscriptions and F09 callback lifecycle | Existing drivers/tests to reuse |
| E | F06 modules, F08 request lifecycle, F10–F13 infrastructure | Require specific services, SAPI, builds, or fault coordination |

Suggested iteration record:

| Finding | Binary/build + INI | Target/topology | Seed + fixture | New functions / lines / branches | Result / artifact |
| --- | --- | --- | --- | --- | --- |
| F01 | Pending | Pending disposable target | 20260914; sparse+dense HLL | Pending | Not run |

## Coverage interpretation and limits

- The server-command catalog report is not C coverage. It cannot identify cold
  cached implementations, callback cleanup, Table, or sessions. Its ignore list
  intentionally excludes many administrative/stateful commands; that should not
  be relaxed merely to increase this percentage.
- Generated `*_arginfo.h` files are fully line-covered mostly through class
  registration. `xxhash.h` contains 641 unexecuted lines, including APIs Relay
  may not call. As a supplementary view, excluding arginfo, bundled xxhash, and
  bundled LZF yields **14,218 / 25,734 lines (55.2%)**, with 34.5% branch coverage.
  Keep the original 59.0% baseline alongside this view; changing the denominator
  is not a testing gain.
- CPU-dispatched CMS/HLL implementations, PHP-version conditionals, TLS/build
  features, assertions, and panic paths cannot all be reached by one campaign.
  Use appropriate build/hardware matrices or direct internal tests for selected
  fallbacks. Avoid treating deliberate crashes or allocator failure paths as
  normal command workload requirements.
- The snapshot alone does not reveal the workload history, Redis versions,
  crash-related counter losses, or whether all objects were rebuilt from the
  current dirty tree. Gains remain unverified until measured on a known build.

## Review validation and retained artifacts

This change is documentation only. Existing README, scripts, logs, reproducer
directories, and coverage reports were retained. A changelog entry accompanies
this report. A derived function index is retained at
`/tmp/relay-review-functions-20260914.json`.

Review/check environment: `/usr/bin/php`, PHP **8.5.10 NTS**, loaded PhpRedis
**6.3.0RC1** and Relay **0.50.1-dev**. The default loaded Relay reports build SHA
`494a4c8eb4d3e13c8ebf624ccd7fa65ce7b0134e`; it is not the reviewed source HEAD.
These versions describe the checks, not proven provenance of the coverage run.
The local launcher explicitly loads `.libs/relay.so` with `/usr/bin/php8.5`;
the debug-tree directory name alone does not identify its worker PHP version.

Redis target/topology: **none contacted for this review**. Live commands/seed:
**none**. Seed `20260914` above is a proposal for the next iteration.
Validation completed successfully:

- `find src tests bin -type f -exec php -l {} +` — syntax checks passed.
- `vendor/bin/phpstan analyse --debug --no-progress` — no errors at level max.
- `vendor/bin/phpunit` — 1,035 tests, 225,493 assertions passed.
- `bin/phpredis-fuzz --help` and `bin/phpredis-coverage --help` — passed.
- `git diff --check` and all report file-link existence checks — passed.

These are repository regression checks, not validation of the proposed live
coverage experiments. PHPUnit's harness tests printed simulated memory-failure
and reproducer messages as part of passing tests; no new live client defect is
being reported here.
