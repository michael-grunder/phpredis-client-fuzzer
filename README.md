# PhpRedis Command Fuzzer

`mgrunder/phpredis-command-fuzzer` embeds randomized Redis command workloads in
PHP applications. It contains the command catalog and argument generators from
RedisClientFuzzer without its outer process supervisors, Redis flushing loop,
Valgrind integration, `rr` integration, or CGI server runner.

Supported client classes are:

- `Redis`
- `RedisCluster`
- `Relay\Relay`
- `Relay\Cluster`

The package discovers 230+ command implementations and supports normal client
methods and raw-protocol variants where an implementation provides both.

> [!WARNING]
> The fuzzer mutates Redis. Some optional categories flush data, block, issue
> administrative commands, alter client state, or deliberately crash PHP. Use
> only an explicitly selected disposable Redis instance or cluster. Never expose
> the HTTP example publicly.

## Requirements and installation

- PHP 8.4 or newer
- PhpRedis (`ext-redis`)
- Relay (`ext-relay`) only when fuzzing Relay clients

```bash
composer require mgrunder/phpredis-command-fuzzer
```

Before the repository is published to Packagist, add it to a neighboring
project as a Composer path repository:

```bash
composer config repositories.phpredis-command-fuzzer path ../phpredis-command-fuzzer
composer require mgrunder/phpredis-command-fuzzer:@dev
```

## Quickstart: use an application-owned client

Passing an existing client is the preferred integration. The fuzzer uses its
current connection, serializer, compression, prefix, authentication, and
topology settings.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;

// Fetch this from your application's container in real projects.
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$result = (new Fuzzer())->run(
    [$redis],
    new RunConfiguration(
        maxSteps: 100,
        seed: 123456,
        commands: ['get*', 'set', 'h*'],
    ),
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
```

The same call accepts a mixed client list, for example `[$redis, $relay]`. A
command runs only on a selected client that exposes the normal method or can
execute that command through its raw-protocol implementation.
`FuzzResult::$outcomes` contains one `InvocationOutcome` for every scheduled
step. Each outcome attributes the command, client index/class, normal or raw
operation, reply, Redis errors, PHP warnings, exception, duration, client mode,
and slot policy. `variant` is currently `null` until commands expose the named
cases described in the robustness plan.

Reply values are safe to persist in JSON: strings use a base64 preview plus a
SHA-256 digest, while arrays are depth- and item-limited. Aggregate command
counts remain available in `FuzzResult::$commands`, and
`FuzzResult::$commandWarnings` exposes warning counts keyed by command.

Human-readable summaries normalize known volatile diagnostic fields before
counting unique messages. For example, generated stream keys in `NOGROUP`
errors are displayed as `'<key>'`, and changing Lua hashes or source line
numbers do not split one failure class into many rows. This affects aggregation
only: `FuzzResult::$outcomes` retains the exact warning, Redis error, and
exception text for reproduction. Normalization rules are deliberately narrow
and live in `DiagnosticNormalizer`.

## CLI

Composer installs `vendor/bin/phpredis-fuzz`, `vendor/bin/phpredis-fuzz-killer`,
and `vendor/bin/phpredis-coverage`.
The fuzzer's generic `--client` option
selects one or more concrete client types rather than using separate PhpRedis
and Relay client-count flags.

```bash
# Standalone PhpRedis, fixed seed and focused command set
vendor/bin/phpredis-fuzz \
    --client=redis \
    --host=127.0.0.1 \
    --port=6379 \
    --steps=1000 \
    --seed=123456 \
    --commands='get*,set,h*'

# Run the same workload across PhpRedis and Relay
vendor/bin/phpredis-fuzz \
    --client=redis,relay \
    --host=127.0.0.1 \
    --steps=500 \
    --seed=123456

# PhpRedis cluster
vendor/bin/phpredis-fuzz \
    --client=redis-cluster \
    --seeds=127.0.0.1:7000,127.0.0.1:7001,127.0.0.1:7002 \
    --steps=500 \
    --seed=123456
```

Use `vendor/bin/phpredis-fuzz --help` for all connection and run options. The
help path does not connect to Redis.

### Disruption fuzzing

`phpredis-fuzz-killer` repeatedly disrupts a running workload. `--mode` selects
what it disrupts each iteration:

- `process` (default) discovers running `phpredis-fuzz` processes through Linux
  `/proc` and sends each one a randomly chosen signal.
- `client` connects to a Redis target and runs `CLIENT KILL` against its normal
  client connections.
- `both` does both every iteration.

```bash
# Signal fuzzer workers (the default mode)
vendor/bin/phpredis-fuzz-killer \
    --signals=SIGINT,SIGTERM,SIGQUIT \
    --sleep=0.8-1.2 \
    --rate=100.0

# Kill clients on a disposable Redis (or cluster) target
vendor/bin/phpredis-fuzz-killer \
    --mode=client \
    --host=127.0.0.1 \
    --port=6379 \
    --client-rate=25 \
    --sleep=0.5-1.0

# Do both, signalling every worker but only killing a quarter of clients
vendor/bin/phpredis-fuzz-killer \
    --mode=both \
    --host=127.0.0.1 --port=7000 \
    --rate=100 --client-rate=25
```

Signal names are case-insensitive and may omit the `SIG` prefix. `--sleep` is an
inclusive minimum-to-maximum range in seconds. `--rate` is the chance, from
`0.0` to `100.0`, of acting on each discovered PID or client during an
iteration; `--process-rate` and `--client-rate` override it for that action.

In `client` and `both` modes the target given by `--host`/`--port` (with an
optional `--auth=PASSWORD` or `--auth=USER:PASSWORD`) is inspected once at
startup. If it belongs to a cluster, every reachable primary and replica is
mapped and one node is chosen at random each iteration; a standalone target
always uses the single given node. Only `normal` client connections are
eligible, and the killer never targets its own inspection connection.

Every action, skipped target, failure, empty scan, and sleep is logged with
elapsed time. Process mode requires Linux `/proc` and PHP's POSIX extension;
client mode requires PHP's redis extension. The utility runs until stopped. Use
it only with disposable fuzzer workers and Redis targets; signals such as
`SIGQUIT` may produce core dumps or other diagnostic artifacts, and `CLIENT
KILL` disconnects live connections.

### Choosing a setting at random

Options with a fixed list of values also accept `random`, which picks one of
the values that the loaded extensions actually support:

| Option | Values |
| --- | --- |
| `--serializer` | `none`, `php`, `igbinary`, `msgpack`, `json` |
| `--compression` | `none`, `lzf`, `zstd`, `lz4` |
| `--relay-failover` | `none`, `primary`, `random_replica`, `replicas`, `all` |
| `--relay-distribute` | `none`, `random`, `random_replica`, `replicas`, `all` |
| `--relay-multikey-reordering` | `none`, `reads`, `writes`, `all` |

`any` is a synonym for `random` and is the form to use with
`--relay-distribute`, where `random` is itself a distribution mode and keeps
its literal meaning.

The choice is made from the run's seed before any client is created, so the
same `--seed` selects the same settings and the same command stream:

```bash
# Reproducible: both runs pick the same serializer and compressor
vendor/bin/phpredis-fuzz --serializer=random --compression=random --seed=123456

# The generated seed is reported in the result; re-run with it to reproduce
vendor/bin/phpredis-fuzz --serializer=random --steps=500
```

The selected values are reported under `environment.clients[]` in the JSON
output. A value whose extension constant the loaded build does not define, such
as `igbinary` without igbinary support, is never picked; naming it explicitly
still fails the run.

Use `--output=json` (the default) for the complete machine-readable report,
including the seed, PHP/client versions, client classes and topology, effective
configuration, elapsed time, selected commands, per-invocation outcomes,
reply-type counts, Redis errors, captured PHP warnings, thrown exceptions, and
`problematic_commands`: commands whose observed
replies were exclusively `false` even though each server involved reports the
command in `COMMAND`. Commands absent from the server are still executed to test
the client's unsupported-command handling, but are excluded from that list.
Cursor-based scan commands are also excluded because `false` is a valid terminal
reply for those client APIs; their replies remain included in the per-command
statistics.
`--output=simple` prints only concise overall statistics. `--output=detailed`
adds an aligned per-command table, a dedicated problematic-command section, and
groups the full Redis error, warning, and exception messages under the command
that produced them.
`--script-log` writes an executable PHP reproduction script containing the
concrete generated calls. `--catch=STRING` stops the workload as soon as a
captured Redis error, PHP warning, or exception contains `STRING`, using a
case-insensitive search. The selected output mode is still written, including
the matching diagnostic in `caught_diagnostic` for JSON output, and the process
exits with a nonzero status.

### Differential cache oracle

Differential testing is disabled by default because it is a different workload
from unconstrained crash fuzzing. Enable it with an ordered client pair: the
reference client first and the Relay subject second.

```bash
vendor/bin/phpredis-fuzz \
    --client=redis,relay \
    --differential \
    --differential-tolerance-ms=10 \
    --differential-poll-ms=1 \
    --steps=1000 \
    --seed=123456
```

The cluster equivalent is `--client=redis-cluster,relay-cluster`. Embedded
callers may also provide a cache-disabled Relay reference. Both clients must be
distinct instances using the same standalone or cluster topology, target,
serializer, compressor, and prefix.

For each atomic normal-path command marked `CACHED`, the oracle:

1. Executes the concrete generated call on the reference.
2. Executes the scheduled call on Relay and preserves that initial result in
   the ordinary invocation outcome.
3. Repeats the identical Relay call to exercise the warm-cache path.
4. If Relay differs, polls until it agrees or the configured tolerance expires.

Each `differential_outcomes` entry is classified as `matched`, `converged`, or
`divergent`. A converged result is retained with its delay and attempt count but
does not fail the CLI. A divergence survives the full tolerance and causes a
nonzero exit. Values, exact Redis errors, and exception behavior are compared;
PhpRedis and Relay exception class names are treated as an inherent client
difference, and Relay's internal exception source-location suffix is ignored,
while the semantic message is still compared. Exact observations and bounded
concrete argument summaries are included in JSON output.

Calls the reference client does not expose are skipped. Nondeterministic reads
(`SRANDMEMBER` and `HRANDFIELD`) and cache-metadata reads (`GETWITHMETA` and
`HGETWITHMETA`) are also skipped until they have command-specific comparison
rules; treating their expected value differences as stale-cache failures would
create false positives. PHP warnings are retained in each observation but are
not parity-tested because the two extensions necessarily emit different class
and call-site text.

The current first slice does not flush Relay's process-wide cache or synthesize
a populate/mutate sequence, because doing so would erase the pending
invalidation state this tolerance mechanism is intended to observe. The
initial Relay read can therefore be cold, warm, or awaiting invalidation; the
immediate repeat and convergence polling distinguish those cases. Explicit
deterministic cold/populate/mutate scenarios remain follow-up work.

## Command coverage

Composer also installs `vendor/bin/phpredis-coverage`, which reports the Redis
commands the catalog does not yet exercise. Unlike `phpredis-fuzz`, it only
issues `COMMAND` and never writes, so it is safe to point at a server the
fuzzer itself must not touch.

```bash
vendor/bin/phpredis-coverage --client=redis --port=6379
```

```
Command coverage for Redis (--client=redis)
Source: 127.0.0.1:6379, 242 server commands, 234 catalog commands
Covered: 188/202 (93.1%), 40 ignored

Not covered, Redis has a method for it (10):
  blpop
  brpop
  ...

Not covered, Redis has no method for it (4):
  bitfield
  ...
```

It works in three steps:

1. `COMMAND` gives the command table the target server actually implements.
   Container subcommands (`config|get`) are folded into their parent because
   the catalog has one class per top-level command.
2. Commands the catalog does not implement are matched against the ignore
   patterns in [`data/coverage-ignore.txt`](data/coverage-ignore.txt): server
   administration, replication and cluster bus internals, connection
   handshakes, stateful pub/sub subscriptions, and deprecated aliases. Patterns
   are applied only to uncovered commands, so an ignore pattern can never hide
   existing coverage.
3. What remains is split with `method_exists()` on the class named by
   `--client`. A command the client exposes a method for is a real catalog gap;
   one it has no method for cannot be fuzzed through that client at all.

`--client` selects only the class checked with `method_exists()`. The command
table is server-wide, so a cluster client type still reads `COMMAND` from the
single `--host`/`--port` node.

Useful options:

| Option | Effect |
| --- | --- |
| `--client=TYPE` | `redis`, `redis-cluster`, `relay`, `relay-cluster` (default: `redis`) |
| `--ignore=PATTERN,...` | Add shell globs to ignore; repeatable |
| `--ignore-file=FILE` | Use `FILE` instead of the shipped ignore list |
| `--no-default-ignores` | Start from an empty ignore list |
| `--commands-file=FILE` | Read command names from a file instead of connecting |
| `--quiet` | Print only the uncovered command names, one per line |
| `--json` | Emit the full report, including the covered and ignored lists |
| `--all` | Also list covered, ignored, and client-side-API commands |

`--help` lists every option and does not connect to Redis. The same report is
available in process through `Coverage\CoverageAnalyzer`, which accepts a
`ServerCommands` list, a `ClientType`, an optional `Registry`, and an optional
`IgnoreList`.

## Minimal HTTP shim

`FuzzerEndpoint` is framework-neutral: give it a callable that returns one
application-owned client, or a non-empty list of clients. `handleJson()` accepts
the request query array and returns JSON without sending headers, so the same
class works in a plain PHP endpoint or inside a framework response.

```php
<?php
// public/internal-redis-fuzz.php

require dirname(__DIR__) . '/vendor/autoload.php';

use Mgrunder\PhpredisCommandFuzzer\Http\FuzzerEndpoint;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;

// Replace this with your container lookup.
$client = $container->get(Redis::class);

$endpoint = new FuzzerEndpoint(
    clientProvider: static fn () => $client,
    configuration: new RunConfiguration(
        maxSteps: 25,
        maxSeconds: 2.0,
        commands: ['get*', 'set', 'h*'],
    ),
);

header('Content-Type: application/json');
echo $endpoint->handleJson($_GET);
```

For a framework controller, return the array from `$endpoint->handle($_GET)`
through the framework's normal JSON response helper.

The request may override only `seed`, `steps`, `seconds`, and `commands`.
Requested step/time values are capped by the server-side configuration.
Dangerous category switches, weights, generation limits, and connection details
cannot be enabled through request parameters. Protect the route with strong
authentication, restrict it to development/staging, and prefer a separate
disposable Redis target.

Example request:

```text
GET /internal-redis-fuzz.php?steps=10&seed=42&commands=get%2A%2Cset
```

## Creating clients outside an application

`ClientFactory` is available when the application does not already own a
configured connection:

```php
use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
use Mgrunder\PhpredisCommandFuzzer\ClientType;

$relay = (new ClientFactory())->create(new ClientConfiguration(
    type: ClientType::Relay,
    host: '127.0.0.1',
    port: 6379,
    timeout: 1.0,
    readTimeout: 1.0,
    auth: ['username', 'password'],
    serializer: 'igbinary',
    compression: 'zstd',
    prefix: 'fuzz:',
));
```

`ClientType` values are `redis`, `redis-cluster`, `relay`, and
`relay-cluster`. Cluster configurations use `seeds` in `host:port` form.

### Relay cluster options

`RelayClusterOptions` carries the Relay-only `Relay\Cluster` tuning options.
Modes are given as names so a configuration can be built and validated without
the Relay extension loaded; the matching `Relay\Cluster` constants are resolved
when the client is created. Passing them with any other `ClientType` is an
error rather than a silent no-op.

```php
use Mgrunder\PhpredisCommandFuzzer\RelayClusterOptions;

$cluster = (new ClientFactory())->create(new ClientConfiguration(
    type: ClientType::RelayCluster,
    seeds: ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002'],
    relayCluster: new RelayClusterOptions(
        failover: 'all',
        distribute: 'random',
        nodeReadTimeout: 0.25,
        multikeyReordering: 'all',
    ),
));
```

| Setting | Option | Modes |
| --- | --- | --- |
| `failover` | `Cluster::OPT_FAILOVER` | `none`, `primary`, `random_replica`, `replicas`, `all` |
| `distribute` | `Cluster::OPT_DISTRIBUTE` | `none`, `random`, `random_replica`, `replicas`, `all` |
| `nodeReadTimeout` | `Cluster::OPT_NODE_READ_TIMEOUT` | Seconds; `0.0` disables the override |
| `multikeyReordering` | `Cluster::OPT_MULTIKEY_REORDERING` | `none`, `reads`, `writes`, `all` |

`RelayClusterOptions` takes concrete mode names only. The CLI expands `random`
and `any` into a mode before constructing it; in-process callers that want the
same behavior can call `OptionChoices::resolve()` after seeding `mt_srand()`.

Each option is applied with `setOption()` and the return value is checked, so a
Relay build that does not understand an option fails the run instead of leaving
the caller believing it took effect. A constant the loaded Relay does not define
is reported the same way. The applied values are read back with `getOption()`
and reported under `environment.clients[].relay_cluster` in the result.

The equivalent CLI options are `--relay-failover`, `--relay-distribute`,
`--relay-node-read-timeout`, and `--relay-multikey-reordering`; they require
`--client=relay-cluster`. When a run selects only PhpRedis clients, supplied
Relay options are ignored with a warning so the same invocation can be reused
while switching clients.

```bash
vendor/bin/phpredis-fuzz \
    --client=relay-cluster \
    --seeds=127.0.0.1:7000,127.0.0.1:7001,127.0.0.1:7002 \
    --relay-failover=all \
    --relay-distribute=random \
    --relay-node-read-timeout=0.25 \
    --relay-multikey-reordering=all \
    --steps=1000 \
    --seed=123456
```

## Custom run configuration

All settings are constructor arguments on the immutable `RunConfiguration`:

| Setting | Default | Meaning |
| --- | ---: | --- |
| `maxSteps` | `100` | Maximum executed operations; `0` disables this limit |
| `maxSeconds` | `0.0` | Wall-clock limit; `0` disables this limit |
| `seed` | generated | Seed returned in the result for reproduction |
| `keys` | `100` | Size of each generated key namespace |
| `shards` | `16` | Number of cluster hash tags used in generated keys |
| `members` | `10` | Maximum generated list/set/hash/zset member count |
| `minLength` | `4` | Minimum generated string length |
| `maxLength` | `32` | Maximum generated string length |
| `maxKeysPerCommand` | `10` | Maximum generated keys for multi-key commands |
| `maxPrefixLength` | `0` | Maximum randomized client prefix length; `0` disables |
| `wrongTypeChance` | `0.0` | Chance from `0.0` to `1.0` of selecting a wrong key type |
| `crossSlotChance` | `0.0` | Chance from `0.0` to `1.0` of forcing a `CROSSSLOT` error; cluster only |
| `commands` | `[]` | Command name, glob, and flag filters |
| `weights` | `[]` | Command or flag weights |
| `raw` | `false` | Enable raw-protocol paths and raw commands |
| `includeBlocking` | `false` | Include blocking commands |
| `includeLocal` | `false` | Include client-local state changes |
| `includeAdmin` | `false` | Include administrative commands |
| `includeFlush` | `false` | Include `FLUSHDB` and `FLUSHALL` |
| `includeCrashing` | `false` | Include deliberately crashing commands |
| `scriptLog` | `null` | Optional executable reproduction script path |
| `catchPattern` | `null` | Case-insensitive Redis error, warning, or exception substring that stops the run after a match |
| `differential` | `false` | Enable ordered reference-versus-Relay cache-read comparisons |
| `differentialToleranceMs` | `10.0` | Maximum time for a mismatched Relay read to converge before it is divergent |
| `differentialPollIntervalMs` | `1.0` | Delay between Relay convergence attempts |

At least one of `maxSteps` or `maxSeconds` must be greater than zero.

Command filters are case-insensitive:

- `get` matches one command.
- `get*` uses shell-style glob matching.
- `@read` selects commands with the read flag.
- `-getex` or `-@scan` excludes a name or flag.
- Positive filters are ORed, then negative filters are removed.

Supported flags are `read`, `write`, `delete`, `flush`, `blocking`, `cached`,
`invalidating`, `expire`, `raw`, `select`, `admin`, `scan`, `local`, `crash`,
and `crossslot`. Safety category switches are applied after name filters, so
explicitly naming `flushall` still requires `includeFlush: true`.

Weights use command names or flags:

```php
$configuration = new RunConfiguration(
    commands: ['@read', 'set', 'del'],
    weights: [
        '@read' => 4.0,
        'set' => 2.0,
        'del' => 0.25,
    ],
);
```

## Cluster hash slots and `crossSlotChance`

### The mechanism

Generated cluster keys carry a hash tag, so the tag alone decides the slot:

```
string:{7}:42
       ^^^ only this is hashed to a slot
```

Redis rejects a multi-key command whose keys live in different slots with
`CROSSSLOT Keys in request don't hash to the same slot`, so a fuzzer that
scattered keys at random would spend most of its cluster budget getting that
one error back instead of exercising real command paths.

The fuzzer therefore opens a *slot scope* for each step, before the selected
command builds any arguments, and every key generated inside that step follows
one policy (`Mgrunder\PhpredisCommandFuzzer\Commands\SlotPolicy`):

| Policy | Applies to | Generated keys |
| --- | --- | --- |
| `SameSlot` | commands Redis requires to be single-slot | share one random hash tag |
| `Unconstrained` | commands flagged `Command::CROSSSLOT` | each picks its own random tag |
| `CrossSlot` | a single-slot command that lost the `crossSlotChance` roll | rotate through distinct tags |

`Command::CROSSSLOT` marks the commands PhpRedis and Relay split across cluster
nodes by slot themselves, rather than handing straight to one node: `DEL`,
`MGET`, `MSET`, `MSETNX`, and `UNLINK`. Those five are deliberately given
mixed-slot key sets, because the per-node splitting and reply-reassembly code
is the interesting target. Every other multi-key command stays inside a single
slot by default. Select them with the `@crossslot` filter:

```bash
bin/phpredis-fuzz --client=redis-cluster --seeds=127.0.0.1:7000 --commands=@crossslot
```

The scope is opened by the runner, so an embedded caller driving commands
directly should call `FuzzConfig::beginCommand($command)` once per command to
get the same behavior. Without it every key picks its own tag
(`Unconstrained`), which is the pre-existing behavior.

Hash tags come from the `shards` setting: `shards: 16` means keys are spread
over 16 distinct tags. It is a *tag* count, not a count of cluster nodes; the
cluster maps those tags onto however many nodes it has.

### Tuning the knob

`crossSlotChance` is the probability that a single-slot command is deliberately
handed keys in different slots anyway, which makes the server return
`CROSSSLOT`. The point is the client-side cleanup path after a partially built
multi-node command fails — rarely hit in normal use, and a good place for leaks
and dangling state.

It behaves like `wrongTypeChance`: a float from `0.0` to `1.0`, rolled once per
step, and out-of-range values are clamped by `FuzzConfig::setCrossSlot()`
(`RunConfiguration` rejects them outright instead).

```php
$configuration = new RunConfiguration(
    maxSteps: 100000,
    crossSlotChance: 0.05,
);
```

```bash
bin/phpredis-fuzz --client=redis-cluster --seeds=127.0.0.1:7000 \
    --crossslot-chance=0.05 --steps=100000
```

Guidance for choosing a value:

- `0.0` (default) — no forced errors. Use for throughput runs and whenever the
  target is not a cluster.
- `0.01` to `0.05` — a normal mixed run. Most steps still do real work, and
  error handling gets exercised steadily over a long run.
- `0.1` to `0.25` — leak hunting under Valgrind or ASan, or reproducing a
  suspected cleanup bug. Expect a large share of replies to be errors.
- `1.0` — every multi-key single-slot command errors. Useful only with a narrow
  `commands` filter to isolate one command's failure path.

Two settings interact with it:

- `crossSlotChance` only has an effect when a cluster client is in the run and
  `shards` is greater than `1`. A single shard cannot produce two slots, and in
  standalone runs the roll is skipped entirely.
- `maxKeysPerCommand` sets how many keys a scattered command spreads over. When
  it exceeds `shards`, the rotation wraps and reuses tags; the command still
  spans several slots and still errors.

The result reports how often the roll actually fired, so a run can be checked
against the configured rate:

```json
{
    "steps": 100000,
    "cross_slot_steps": 4983
}
```

Note that this counts steps *selected* for scattering. Commands taking a single
key produce a valid request regardless, so the number of `CROSSSLOT` errors
returned is lower than this count.

The runner seeds PHP's process-global Mersenne Twister because the extracted
command implementations use `rand()`/`mt_rand()`. The selected seed and concrete
arguments are reproducible when the same PHP/client versions, server topology,
configuration, and initial Redis state are used. Avoid unrelated calls to
`rand()` in the same process while a run is active.

## Development

```bash
composer install
find src tests bin -type f -exec php -l {} +
vendor/bin/phpstan analyse --debug --no-progress
vendor/bin/phpunit
bin/phpredis-fuzz --help
bin/phpredis-coverage --help
```

PHPStan runs at level `max`. PHPUnit unit tests do not require a Redis server.
Run live integration checks only against an explicitly selected disposable
standalone instance or cluster.
