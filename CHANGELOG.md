# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Add `redis-client-tester-sapi` for seeded workloads in concurrent HTTP requests
  against persistent PHP built-in server workers, with idle intervals, bounded
  requests, worker-death detection, process-group cleanup, and per-request scripts
  and responses. Integrate harness startup settings, descendant cores, retained
  SAPI artifacts, rr/ASAN recording, and Valgrind server wrappers.
- Add opt-in `--persistent` / `ClientConfiguration::persistent` for all four
  clients, enabled by the SAPI runner and preserved in call logs and reconnects.

- Add opt-in TLS transport and JSON SSL context options to the fuzzers, harness
  startup probes, coverage, command statistics, and client killer. Support all
  four clients through `TlsConfiguration`, preserve TLS on reconnects and in
  reproducer scripts, and encrypt the differential runner's neutral RESP socket.

- Add `--saturate-mode=churn` (`SaturationMode::Churn`): each saturation step
  seeds a key, then wraps an optional whole-value read in randomly chosen
  partial Relay-cached reads (lengths, member checks, field and range reads), so
  Relay's partial cache entries are created, upgraded to whole values, and hit.
- Add `asan-saturate.sh`, an ASAN campaign focused on Relay cache churn with
  randomized `relay.maxmemory`, eviction policy, threshold, and sample size.

- Add the `phpser` serializer choice (when PhpRedis defines
  `Redis::SERIALIZER_PHPSER`), random `OPT_PACK_IGNORE_NUMBERS` and backoff
  option changes, and `AGGREGATE COUNT` for zset union/intersection commands.
- Add `FAILOVER=N` to `asan.sh` to promote a random cluster replica every N
  seconds during a campaign; `asan.sh` now also selects `phpser`.

- Add an inline tuning reference to `asan.sh` covering inferior workload options,
  campaign limits, command filters, cache pressure, and proxy prerequisites.
- Add local `conf.d/build-redis.sh` and `conf.d/build-relay.sh` helpers to build
  ASAN-instrumented extensions from working source copies, retain build logs and
  previous modules, and copy successful builds into `conf.d`. PhpRedis enables
  zstd, lz4, msgpack, and igbinary support.
- Add `bin/redis-client-tester-repros`, a read-only TUI (and `--list`/`--show` text mode)
  for browsing harness reproducers, with a per-capture defect headline from the
  parsed sanitizer report, a glibc assertion, or a gdb backtrace of the core,
  and grouping of captures by address-free defect signature.
- Add `--asan` and `--asan-runtime FILE` to `redis-client-tester-harness`, a
  `sanitizers` capture kind (`reproducers/sanitizer/`), and `bin/redis-client-tester-asan`
  for single ASAN runs. Sanitizer reports in stderr are classified regardless of
  exit status, recorded as `sanitizer_message` and `asan_environment` in
  `meta.json`, and required again during step reduction.
- Add `bin/redis-client-tester-diff`, a stateful differential fuzzer that runs
  identical, seeded, explicitly represented testcases through PhpRedis and
  Relay from identical Redis state (seeded and inspected through a separate
  raw RESP connection), compares replies with Redis-semantics-aware
  canonicalization, and compares the resulting per-prefix state. Confirmed
  divergences are self-checked, analysed per operation, minimized, deduplicated
  by signature, and saved as `case.json`, `metadata.json`, and a standalone
  `reproduce.php`. Keys stay under a per-run prefix, flush commands require
  `--allow-flush`, and the binary follows the `redis-client-tester-harness` seed,
  `{steps}`, and exit-code conventions.
- Add the `@common` command flag for 60 curated everyday Redis commands.
- Add `RunConfiguration::commandGroups` for intersections of OR filter groups,
  retaining groups in reports and preserving server-controlled HTTP groups.
- Add opt-in `evilresp.fresh_seed=1` hook argument for a fresh deterministic
  proxy seed on each CHAOS arm attempt, preserving concrete per-node seeds in
  reproduction scripts and keeping the default one-seed-per-run behavior.
- Add `--pick-client` to the fuzzer CLI to select matching clients from mixed
  standalone/cluster lists using the target port's topology, while preserving
  counts and deliberate single-topology mismatches.
- Add opt-in `cache-hll` and `cache-collections` scenarios for F01/F02: bounded
  HLL and collection warming/mutation fixtures, server count parity, Relay cache
  counter observations, and server-free regression tests.
- Add a source-backed Relay coverage opportunity report with prioritized test
  scenarios and a plan for measuring incremental coverage.
- add a changelog
- Add a local coverage stress-launch script with explicit inferior PHP selection
  and reference settings for the standalone evilresp proxy.

### Changed

- Rename the Composer package to `mgrunder/redis-client-tester`, the namespace
  to `Mgrunder\RedisClientTester`, and every binary to the `redis-client-tester`
  prefix. Rename `Fuzzer`, `FuzzResult`, `FuzzerEndpoint`, and
  `FuzzKillerApplication` to `Tester`, `TestResult`, `TesterEndpoint`, and
  `ClientKillerApplication`; update hooks, tests, documentation, generated script
  identifiers, and local shell helpers. This is a breaking rename without aliases;
  retain existing diagnostic artifacts and the differential generator's seed salt.

- Build Relay and PhpRedis concurrently in `conf.d/build-extensions.sh`, waiting
  for both builds and succeeding only when both succeed.

- Repeated `--commands` arguments now intersect in the strict/coercive fuzzer
  and command catalog CLI. Comma-separated positives still use OR, exclusions
  still remove matches, and all safety gates remain in force.
- Rework `fuzz-coverage.sh` into a multi-lane campaign: per-job evilresp
  proxies (`chaos`), a direct high-throughput Relay lane with forks, saturation,
  the adaptive cache and deserialization callbacks (`direct`), strict
  differential and full-scenario oracle lanes on both topologies (`oracle`),
  and subscription callback lanes (`subscribe`), with `preflight` and `summary`
  subcommands. Every lane now randomizes serializers and compressors, and
  `fuzz.ini` also draws Relay lock types, `relay.default_pconnect` and
  `zend.enable_gc` per run.

- Group new harness reproducers under `crash/`, `memory/`, `leak/`, `timeout/`,
  or `failure/` in the output directory, retaining existing captures and the
  separate `failed/` directory for incomplete recordings.

### Fixed

- Fix PHPStan errors in command dispatch, differential helpers, value corpus
  generation, and the evilresp test's mutable command snapshots without changing
  seeded workloads or suppressing diagnostics.

- Flag `BITPOS`, `PFCOUNT`, `ZCOUNT`, `ZMSCORE`, `ZRANK`, `ZREVRANK`, and
  `ZSCORE` as `cached`; Relay serves all of them from its in-memory cache, but
  `@cached` filters skipped them.
- Reconnect standalone `Relay\Relay` clients after `close`/`pclose`; previously
  every later step of the run threw "Not connected".
- Never send local client helpers through raw chaos, which only produced
  "unknown command" errors.
- Accept Relay 0.60's nullable `connect()` context in the test fixture.
- Fail harness startup before spawning jobs when a `{port}` pool endpoint
  cannot connect or answer PING, listing each failed host/port and its error.
- Reject unknown command flags even when an earlier OR pattern matched or a
  previous filter group selected no commands.
- `hooks/evilresp.php` no longer aborts the whole run with "preCommand hook
  evilresp failed: Must be connected to get mode" when a Relay client was
  closed by a `close`/`pclose` step or the `disconnect-recovery` scenario; a
  client that cannot report its mode is skipped until it reconnects.
- The `disconnect-recovery` scenario records the standalone endpoint and
  timeouts before `close()` (Relay's `getHost()`/`getPort()` throw afterwards),
  reconnects with the same connect timeout and restores `OPT_READ_TIMEOUT`
  (a bare `connect()` leaves both clients with unlimited timeouts, which hung
  a run inside Relay's `recv()` through evilresp), and judges the
  `close-restores-atomic-mode` postcondition only after reconnecting, so a
  Relay client that fails that postcondition is no longer left disconnected
  for every later scenario and fuzz step in the run.
- `--output=json` no longer fails with "Malformed UTF-8 characters" (exit 1)
  when a generated value or reply contains invalid UTF-8; such bytes are now
  substituted so differential observations stay readable.

- Correct the local fuzz launcher's `--pick-client` flag and use farm PHP for
  inferiors without redundantly loading its built-in extensions from `fuzz.ini`.
- Clarify worker PHP selection for coverage builds and correct the local coverage
  INI's extension paths and eviction-policy choices.
