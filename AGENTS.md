# PhpRedis Command Fuzzer agent guide

## Purpose

This package extracts the reusable randomized command catalog from
RedisClientFuzzer. It is a Composer library first: consumers must be able to run
the fuzzer with application-owned clients from CLI, HTTP, workers, or tests
without importing process supervisors or assuming a particular framework.

Correctness, reproducibility, useful failure details, and safe defaults take
priority over raw command throughput.

## Supported clients

Keep these four classes first-class:

- `Redis`
- `RedisCluster`
- `Relay\Relay`
- `Relay\Cluster`

Generic behavior should support all four unless it is explicitly client- or
topology-specific. Prefer `class_exists()`, `defined()`, `method_exists()`, and
other capability checks for optional or prerelease features. PhpRedis is the
required compatibility API; Relay is optional for package consumers.

## Repository map

- `src/Commands/Command/` contains concrete Redis command implementations.
- `src/Commands/Command.php` owns common execution, warning capture, error
  handling, script logging, flags, and shared client handling.
- `src/Commands/FuzzConfig.php` generates command arguments and key names.
- `src/Commands/Registry.php` discovers the concrete command catalog.
- `src/Fuzzer.php` filters, weights, selects, and executes commands.
- `src/RunConfiguration.php` is the immutable public workload configuration.
- `src/ClientFactory.php` and `src/ClientConfiguration.php` are optional client
  construction helpers; embedded callers should be able to pass existing clients.
- `src/Http/FuzzerEndpoint.php` is the framework-neutral HTTP adapter.
- `src/Cli/Application.php` and `bin/phpredis-fuzz` provide the Composer binary.
- `src/Data/` and `data/` contain geo and stream workload fixtures.
- `tests/` contains server-free PHPUnit unit tests.

## Engineering conventions

- Use PSR-4 namespace `Mgrunder\PhpredisCommandFuzzer` and four-space
  indentation.
- Keep public configuration explicit, typed, validated, and documented.
- Route command calls through `Command::exec()`, `execRaw()`, or `cmd()` so
  diagnostics, warnings, and reproduction logging remain intact.
- Preserve cluster hash tags for related keys that must share a slot.
- Do not normalize away differences between PhpRedis and Relay merely to make a
  fuzz run pass. Record the client and the observed outcome.
- Preserve seeded selection and log concrete generated arguments. The existing
  catalog uses process-global `rand()`/`mt_rand()`; do not add hidden randomness
  from `random_bytes()` or `random_int()` to a seeded execution path.
- Keep expected Redis errors separate from PHP warnings and thrown exceptions.
  Do not turn signals, timeouts, or memory failures into ordinary replies.
- Gate extension-specific constants and methods. A PhpRedis-only consumer must
  be able to load the registry without Relay installed.
- Update `README.md` when changing public APIs, configuration defaults, CLI
  options, HTTP behavior, supported clients, dependencies, or safety behavior.

## Command changes

Concrete filenames, namespaces, and class names must remain compatible with the
registry's filename-derived loader. Set the narrowest key `type()` and all
applicable flags. In particular, mark blocking, local, raw, admin, flush, and
deliberately crashing commands accurately because these flags enforce default
safety gates.

Set `Command::CROSSSLOT` only on commands the clients themselves split across
cluster nodes by key slot (currently `DEL`, `MGET`, `MSET`, `MSETNX`, and
`UNLINK`). The flag relaxes the single-slot key generation in
`FuzzConfig::beginStep()`, so marking a command that Redis actually requires to
be single-slot turns its steps into permanent `CROSSSLOT` errors.

Implement the applicable surfaces:

- `FuzzInterface` for normal client methods
- `FuzzRawInterface` for raw protocol coverage
- `ProxyInterface` only for source-server sampling support

Reuse base command shapes and traits. Test serializers, compressors, prefixes,
transactions, pipelines, wrong-type values, and standalone/cluster variants in
proportion to the change.

## Safety

This package intentionally mutates Redis. Never run an automated integration
check against a shared, production, or valuable target. Do not enable admin,
flush, blocking, raw, local, or crashing categories merely to increase coverage.

Do not expose the HTTP shim publicly. Request-controlled options must not be
able to expand server-side limits or enable dangerous categories.

Do not delete or overwrite reproducer scripts, logs, reports, core dumps,
Valgrind output, `rr` traces, or unrelated untracked files. They may be the only
evidence of a client defect.

## Validation

Install dependencies with `composer install`. Do not run `composer update` or
change constraints unless the task requires it.

For every PHP change, run syntax checks on changed files. Before handoff run:

```bash
find src tests bin -type f -exec php -l {} +
vendor/bin/phpstan analyse --debug --no-progress
vendor/bin/phpunit
bin/phpredis-fuzz --help
```

PHPStan must remain clean at level `max`; do not add a baseline or ignored
errors. Unit tests must not require Redis. Use a fixed seed and a small command
set for live tests only when the user has explicitly selected a disposable
Redis target.

In the final handoff state the PHP binary/version, loaded PhpRedis and Relay
versions, Redis target/topology, commands and seed used, checks run, and any
artifacts retained.
