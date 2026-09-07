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
- `ext-pcntl` only for forked concurrent runs (`phpredis-fuzz --forks`)

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
command runs only on a selected client that exposes the normal method, can
execute that command through its structured raw-protocol implementation, or
has `rawCommand()` when raw chaos is enabled. `rawChaos` adds a third operation
which keeps the selected command name (and cluster routing argument)
deterministic while filling the remaining argument positions with seeded,
arbitrary scalar values.
`FuzzResult::$outcomes` contains one `InvocationOutcome` for every scheduled
step. Each outcome attributes the command, client index/class, normal, raw, or
raw-chaos operation, reply, Redis errors, PHP warnings, exception, duration,
client mode, and slot policy. `variant` is currently `null` until commands
expose the named cases described in the robustness plan.

Reply values are safe to persist in JSON: strings use a base64 preview plus a
SHA-256 digest, while arrays are depth- and item-limited. Aggregate command
counts remain available in `FuzzResult::$commands`, and
`FuzzResult::$commandWarnings` exposes warning counts keyed by command.

Runs containing either `Relay\Relay` or `Relay\Cluster` also sample the global
`Relay\Relay::stats()` counters before the workload, after every 100 commands,
and at the end. `FuzzResult::$relayStats` reports the latest hit, miss, OOM, and
memory counters together with the highest observed active and used memory. When
the loaded Relay build exposes an `evictions` counter it is reported as well;
older builds simply omit it. Human-readable output prints these and the other
summary counters with thousands separators, while JSON output keeps raw
integers.

Human-readable summaries normalize known volatile diagnostic fields before
counting unique messages. For example, generated stream keys in `NOGROUP`
errors are displayed as `'<key>'`, and changing Lua hashes or source line
numbers do not split one failure class into many rows. This affects aggregation
only: `FuzzResult::$outcomes` retains the exact warning, Redis error, and
exception text for reproduction. Normalization rules are deliberately narrow
and live in `DiagnosticNormalizer`.

## Hooks

Hooks come in two kinds. *Invocation hooks* gate what the fuzzer is about to
do, and *lifecycle hooks* observe what it did. Both are loaded from the same
trusted `--hook` files, or registered directly on a `HookRegistry`.

### Invocation hooks

Trusted PHP hook files can allow, reject, or replace generated client
invocations immediately before reproduction logging and client dispatch. This
provides a final safety boundary regardless of which randomized branch produced
the arguments. Load one or more files explicitly with repeatable `--hook`
options:

```bash
bin/phpredis-fuzz \
    --include=local \
    --hook=hooks/no-msgpack.php \
    --steps=100000
```

The included `hooks/no-msgpack.php` example prevents any `setOption()` argument
tuple that PhpRedis or Relay would coerce to
`setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_MSGPACK)`. It registers
nothing when the loaded PhpRedis does not define the optional msgpack constant.
It uses symbolic constants rather than numeric values so it remains correct
across extension builds. Invocation hooks do not wrap initial `ClientFactory`
configuration, so pair this hook with the default `--serializer=none` or an
explicit `--serializer=none,php,igbinary,json` subset when startup selection is
randomized.

A hook file may return a callable that accepts a `HookRegistry`, or a single
hook object whose registration ID is derived from the filename. An object is
registered under every surface it implements, so one object can be both an
`InvocationHook` and a lifecycle hook. The registry form can declare several
hooks. Safety policies should use
`addGuard()` so they inspect the final arguments after every ordinary hook has
had an opportunity to replace them:

```php
<?php

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;

return static function (HookRegistry $hooks): void {
    if (!defined('Redis::SERIALIZER_MSGPACK')) {
        return;
    }

    $hooks->addGuard(
        'no-msgpack-serializer',
        new readonly class(
            Redis::OPT_SERIALIZER,
            constant('Redis::SERIALIZER_MSGPACK'),
        ) implements InvocationHook {
            public function __construct(
                private int $serializerOption,
                private int $msgpackSerializer,
            ) {
            }

            public function beforeInvocation(PendingInvocation $call): HookDecision
            {
                if (strcasecmp($call->method, 'setOption') !== 0
                    || count($call->arguments) < 2
                    || $this->integerValue($call->arguments[0]) !== $this->serializerOption
                    || $this->integerValue($call->arguments[1]) !== $this->msgpackSerializer) {
                    return HookDecision::allow();
                }

                return HookDecision::reject('Value would select the msgpack serializer');
            }

            private function integerValue(mixed $value): int
            {
                return is_object($value) ? 1 : (int) $value;
            }
        },
    );
};
```

For dynamic behavior, implement `InvocationHook` and register it with
`$hooks->add($id, $hook)`. Its `beforeInvocation(PendingInvocation $call)`
method returns `HookDecision::allow()`, `HookDecision::reject($reason)`, or
`HookDecision::replace($arguments)`. Replacements are passed to subsequent
hooks in registration order; the first rejection wins. Guards registered with
`$hooks->addGuard($id, $guard)` then inspect the final invocation and may allow
or reject it, but may not replace its arguments. Hook source paths and
SHA-256 hashes are reported under `configuration.hooks`, while rejection counts
and reasons are reported under `hook_rejections`. Rejected candidates consume
seeded randomness but do not consume the executed-step budget. Ten thousand
consecutive rejections fail the run as an impossible workload.

Embedded callers can construct a `HookRegistry` directly and pass it to
`new Fuzzer($hooks)`. Hook files execute arbitrary PHP and are never discovered
or loaded automatically; only load files you trust.

### Lifecycle events

Lifecycle hooks observe the run. They cannot reject or rewrite anything, so
they never change which commands execute or what the fuzzer reports. Four
events are available:

| Event | Payload | When |
| --- | --- | --- |
| `postConstructor` | `ClientEvent` | Once per client object, before the fuzzer inspects or uses it |
| `preCommand` | `PendingInvocation` | Immediately before each client call, with the final arguments |
| `postCommand` | `CompletedInvocation` | After each client call, with its reply or the throwable it raised |
| `preDestructor` | `ClientEvent` | When the fuzzer is finished with a client, while it is still connected and usable |

`ClientEvent` carries the client itself plus its position in the run's client
list; `isCluster()` and `isRelay()` are conveniences, and hook code is free to
type-check `Redis`, `RedisCluster`, `Relay\Relay`, and `Relay\Cluster`
directly. `preDestructor` is not a PHP destructor: it runs while the client is
still fully usable, which is the point of the event. `preCommand` and
`postCommand` do not fire for an invocation an invocation hook rejected, and
`postCommand` fires for a failed call with `$throwable` set and `$result`
null.

Register closures with `onPostConstructor()`, `onPreCommand()`,
`onPostCommand()`, and `onPreDestructor()`, or register an object implementing
any combination of `PostConstructorHook`, `PreCommandHook`, `PostCommandHook`,
and `PreDestructorHook` with `addLifecycleHook($id, $hook)`. IDs must be unique
per event. A listener that throws aborts the run with `HookExecutionFailed`
naming the event and ID, except a `preDestructor` failure while a run is
already failing, which is logged so it cannot mask the original throwable.

```php
<?php

use Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\CompletedInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;

return static function (HookRegistry $hooks): void {
    $hooks->onPostConstructor('note-client', static function (ClientEvent $event): void {
        fwrite(STDERR, "client {$event->clientIndex}: {$event->client::class}\n");
    });

    $hooks->onPostCommand('slow-calls', static function (CompletedInvocation $call): void {
        if ($call->durationSeconds > 0.25) {
            fwrite(STDERR, "{$call->method} took {$call->durationSeconds}s\n");
        }
    });

    $hooks->onPreDestructor('final-info', static function (ClientEvent $event): void {
        // The client is still connected here, so it can still be queried.
        $info = $event->isCluster() ? 'cluster' : 'standalone';
        fwrite(STDERR, "done with {$event->client::class} ({$info})\n");
    });
};
```

`hooks/lifecycle-example.php` registers all four events and carries commented
out variants of the noisier things each one is good for: tracing every call,
asserting on a reply, configuring a client before the workload starts, and
inspecting the server once the run is over. Load it with
`--hook=hooks/lifecycle-example.php`.

`postConstructor` fires once per client object. Clients built by
`ClientFactory` are announced there, so a CLI run sees a client before its
options are exercised; clients an embedded caller passes to `Fuzzer::run()` are
announced at the start of the run. A client handed to both is still announced
once. `preDestructor` fires at the end of every `Fuzzer::run()`, after the
run's own accounting is closed, so commands a hook issues are not counted in
the report.

Every call routed through `Command::exec()`, `execRaw()`, or `cmd()` raises the
command events, including cache saturation traffic. Stateful scenarios call the
client directly and do not.

Registered lifecycle IDs are reported under `configuration.hooks.listeners`.
Events with no listeners cost one null check per invocation, so an unhooked run
is unaffected.

## CLI

Composer installs `vendor/bin/phpredis-fuzz`,
`vendor/bin/phpredis-fuzz-coercive`, `vendor/bin/phpredis-fuzz-killer`,
`vendor/bin/phpredis-fuzz-harness`, `vendor/bin/phpredis-commands`,
`vendor/bin/phpredis-coverage`, and `vendor/bin/phpredis-commandstats`.
The fuzzer's generic `--client` option selects one or more concrete client
types. Append `:COUNT` to create multiple instances of a type; the count
defaults to one and the total is limited to 10,000 clients. Each step selects
one eligible instance at random, so `--steps` remains the total number of
sequential operations rather than a per-client or parallel-operation count. Use
`--forks=N` to run the workload concurrently in forked child processes.

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

# Exercise client-count-sensitive paths with eight PhpRedis connections
vendor/bin/phpredis-fuzz \
    --client=redis:8 \
    --host=127.0.0.1 \
    --steps=5000 \
    --seed=123456

# PhpRedis cluster
vendor/bin/phpredis-fuzz \
    --client=redis-cluster \
    --seeds=127.0.0.1:7000,127.0.0.1:7001,127.0.0.1:7002 \
    --steps=500 \
    --seed=123456
```

### Forked concurrent runs

`--forks=N` runs the workload in `N` `pcntl_fork()`ed children instead of the
current process. The parent forks and then only supervises: it never executes
commands itself, prints each child's result whole as that child finishes, waits
for every child, and exits with the most severe child status (`78` beats `1`
beats `0`). A child killed by a signal fails the run and is reported on stderr
with its signal number. `SIGINT` and `SIGTERM` are forwarded to the children; a
second one stops supervising and takes the signal's default action.

Each child runs the whole `--steps` or `--seconds` workload, so `N` children
execute `N` times the requested operations concurrently. Child seeds are drawn
from the reported `--seed`, so the children fuzz different command streams while
the run as a whole stays reproducible from `--seed` and `--forks`; each child
also reports its own seed, which reproduces that child alone when the run's
settings are given explicitly. `--script-log=repro.php` becomes `repro.1.php`,
`repro.2.php`, and so on, so concurrent children never overwrite one another's
reproduction scripts.

```bash
# Eight concurrent PhpRedis workloads against a disposable target
vendor/bin/phpredis-fuzz \
    --client=redis \
    --forks=8 \
    --steps=10000 \
    --seed=123456
```

`Redis` and `RedisCluster` connections cannot be used after a fork, so each
child re-creates those clients from the same `--client` configuration before it
starts fuzzing. `Relay\Relay` and `Relay\Cluster` handle forking themselves, so
children keep the clients they inherited — exercising that machinery is a
deliberate part of what `--forks` tests. The option requires `ext-pcntl` and
accepts at most 1,024 children; `--forks=0` (the default) does not fork.

`--scenarios` accepts `transaction-exec`, `transaction-discard`, and
`watch-unwatch-discard`. Use `none` to explicitly disable them, or `random` to
select a deterministic random subset (including no scenarios) from the full
catalog. The subset is derived from the workload seed, so a reported seed
reproduces the selection. Explicit names may be combined with `random` to add
those scenarios to a random subset of the named choices.

Use `vendor/bin/phpredis-fuzz --help` for all connection and run options. The
help path does not connect to Redis.

The fuzzer binaries (`phpredis-fuzz`, `phpredis-fuzz-coercive`, and
`phpredis-fuzz-killer`) exit `0` when the run finished with nothing caught, `1`
when the run executed and something failed (a caught diagnostic, a differential
divergence, or a failed scenario), and `78` for a *startup failure*: the command
line was rejected, or it described an unusable configuration, so no command was
executed. Only command-line handling produces `78` — a target that cannot be
reached is a normal `1`, because the same invocation may well work against a
reachable server. Supervisors, and `phpredis-fuzz-harness` in particular, use
that distinction to tell "this invocation is broken" from "this run found
something".

To inspect the command catalog without connecting to Redis, run
`vendor/bin/phpredis-commands`. It prints each command's type, category flags,
and available client, raw-protocol, and proxy-sampling surfaces. Its optional
`--commands` argument uses the same case-insensitive names, globs, flag filters,
and exclusions as the fuzzer:

```bash
vendor/bin/phpredis-commands --commands='get*,-getex,@crossslot'
```

The catalog listing includes every safety category so filters such as
`--commands=@blocking` can be checked without enabling or executing those
commands.

### Client invocation typing

Client command calls use strict PHP typing by default. This affects only the
boundary that invokes `Redis`, `RedisCluster`, `Relay\Relay`, or
`Relay\Cluster`; workload selection, argument generation, diagnostics, and the
rest of the fuzzer remain unchanged.

Use `--invocation-mode=coercive` to exercise PHP's coercive scalar argument
handling in the client extensions:

```bash
vendor/bin/phpredis-fuzz \
    --invocation-mode=coercive \
    --client=redis \
    --steps=1000 \
    --seed=123456
```

`vendor/bin/phpredis-fuzz-coercive` is a convenience entrypoint with the same
coercive default; it accepts all normal fuzzer options. Passing
`--invocation-mode=strict` overrides that default. The selected mode is stored
in `FuzzResult::$configuration` and emitted as both metadata and the
`declare(strict_types=...)` setting in reproduction scripts.

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

### Parallel fuzzing harness

`phpredis-fuzz-harness` runs many `phpredis-fuzz*` workers in parallel behind an
AFL-style dashboard, and turns a crash, timeout, memory leak, or non-zero exit
into a saved reproducer directory. It never interprets the workload itself — it
launches the command you give it after `--`, substituting a few placeholders per
run.

```bash
vendor/bin/phpredis-fuzz-harness \
    --jobs 4 \
    --reduce steps \
    --rr --rr-chaos \
    --capture crashes,leaks,timeouts \
    --run-timeout 120 \
    --php "$(farmroot)/sapi/cli/php" \
    --php-ini "$(farmroot)/php.ini" --php-args -drelay.maxmemory=1g \
    --port 7000 7001 7002 7003 --isolate-ports \
    -- \
    vendor/bin/phpredis-fuzz-coercive \
        --client=relay-cluster --seconds 2 --steps {steps} \
        --host=127.0.0.1 --port={port}
```

Placeholders in the template are replaced for every run: `{port}` (a port drawn
from `--port`), `{steps}` (the current step budget), `{seed}` (the run's seed,
also appended as `--seed=N` when the template does not set it), `{job}` (the job
slot, `0 .. jobs-1`), and `{run}` (the global run counter).

`--php` picks the interpreter; `--php-args` adds startup arguments in front of
the fuzzer script, so `--php /usr/bin/php --php-args -drelay.maxmemory=1g` runs
every worker as `/usr/bin/php -drelay.maxmemory=1g ...`. It may be repeated, and
a value that holds several arguments is split the way a shell would
(`--php-args="-d a=1 -d b=2"`); nothing is passed through a shell, so quoting is
only interpreted there. `--php-ini FILE` is shorthand for `--php-args "-c FILE"`
— the file must exist and be readable or the harness refuses to start, and a
copy of it is stored in every reproducer as `php.ini`, with the absolute path
recorded in `command.txt` and `meta.json`, so a capture stays reproducible after
the original ini is edited or deleted. The version banner and the debug-build
check are probed with the same ini and arguments the runs use.

Port handling is deliberately two-mode. Give one or more `--port` values;
`--port-select` is `cycle` (round-robin, default) or `random`. Without
`--isolate-ports` several concurrent runs may share a server, which exercises
the shared-target edge cases; with `--isolate-ports` a port is only ever handed
to one running job at a time, so runs are isolated in Redis and effective
concurrency is capped at the number of ports.

`--capture` is a comma list of what to save as a reproducer; the default is
`crashes`. The kinds are:

- `crashes` — the run dies from a crashing signal
  (`SIGSEGV`/`SIGABRT`/`SIGBUS`/`SIGILL`/`SIGFPE`/`SIGSYS`/`SIGTRAP`, or a
  `128 + signal` exit).
- `leaks` — a leak report was printed to stderr, from either allocator: a
  **debug** PHP build's Zend memory manager
  (`=== Total N memory leaks detected ===`), or Relay's own shared allocator
  built with `RELAY_SH_TRACK_LEAKS`
  (`relay.c:5636 leaked block of 112 bytes at 0x… allocated by pid …`). Leaks do
  not change the exit code, so the harness only scans for them when `leaks` is
  requested, and warns at startup if `--php` is not a debug build. The
  reproducer's `meta.json` records `leak_count`, `leak_bytes`, `leak_site`, and
  `leak_source` (`zend-mm` or `relay-shm`).
- `timeouts` — the run was still going when it hit `--run-timeout` and the
  harness killed it. Termination escalates: `SIGTERM` first for a clean exit,
  then `SIGKILL` after a short grace period if it is still alive. The
  reproducer's `meta.json` records `run_timeout_seconds` and `kill_signal`
  (`SIGTERM` or `SIGKILL`, so a genuinely wedged process is visible). Runs only
  time out when `--run-timeout` is set.
- `failures` — any other non-zero exit (the fuzzer's own "caught a diagnostic"
  exit).

`--capture crashes,leaks,timeouts,failures` captures everything. Each capture
becomes `<output>/<harness-pid>.<nnnnn>/` — for example `48213.00001` — holding
`command.txt`, `meta.json`, `stdout.log`, `stderr.log`, any matching core dump,
the `php.ini` copy when `--php-ini` was used,
and — under `--rr` — the finalised `rr-trace/` (the harness waits for rr's
`incomplete` sentinel to clear, up to `--trace-timeout`). The seed, run number,
signal, exit code, and capture time all live in `meta.json`. Each campaign works
in its own `<output>/.work/<harness-pid>/` and removes only that subtree when it
exits, so a harness sharing an output directory with another one never deletes
or reuses a live run's directory. Work directories and rr traces for runs that
are not captured are deleted; `--keep-work` keeps them.

`--run-log FILE` appends every finished run's stdout to one file. Only a
captured run keeps its output otherwise — the rest is deleted with the work
directory — so this is the way to watch or mine what the fuzzers actually
reported across a whole campaign rather than only where it broke. Each run
contributes one block: a `#` header line naming it, followed by that run's
output verbatim.

```
# 2026-09-05T18:22:41+00:00 harness=48213 run=17 seed=920144 port=7001 steps=5000 pid=48260 4.02s :: ok
{
    "seed": 920144,
    ...
}
```

The label after `::` is the same one the dashboard shows — `ok`, `SIGSEGV`,
`timeout (SIGKILL)`, `leak 2 (2048 bytes, zend-mm)`, `exit 1`, or `stopped` for
a run the shutdown had to kill — so `grep '^#' runs.log | grep -v ':: ok'` lists
every run that did not pass, and `grep -v '^#' runs.log | jq …` reads the run
documents as the stream of JSON that `--output=json` produces. Blocks are
written whole by the harness after each child is reaped, so concurrent runs
never interleave; the trade-off is that a run appears in the log when it
finishes, not while it runs. A run that printed nothing (killed before it
flushed, for instance) still gets a header, followed by `# (no output)`.

The file is appended to and never truncated — several campaigns may share one,
which is why each header carries the harness pid — and the resolved path is
recorded in `<output>/<harness-pid>.run-info.txt` as `run log:`. Reduction
re-runs are not logged: they replay one seed many times and would bury the
campaign. Only stdout is captured; stderr (`--verbose` command logging, leak
reports, rr's diagnostics) stays in the reproducer directories and under
`--keep-work`. A path that cannot be opened for appending is a startup error,
before any run is spawned.

A run whose stderr holds rr's own death is **not** a finding and is not
captured at all. When rr itself dies — a terminal resize stopping the tracee
inside `Task::spawn()`, or another process pulling the trace directory out from
under it — the harness' child *is* rr, so the recorder's `SIGABRT` arrives
looking exactly like a crash in the client. An `[FATAL src/…]` line or a
`=== Start rr backtrace:` dump in a run's stderr therefore discards it whatever
state the trace was left in, since rr can also die after finalising a perfectly
good recording. Those runs are counted as *rr aborts*, appended one line each to
`<output>/rr-aborts.log` (time, harness pid, run, seed, and rr's diagnostic),
and reported in the closing summary; the harness warns once when they account
for half or more of a campaign, which usually means the machine is unhealthy or
a second harness is writing to the same output directory. Use `--keep-work` to
keep the artifacts of one for inspection.

A capture whose rr trace never finalised, with nothing from rr to say the
recorder was at fault, is still a failure — just one that cannot be replayed,
since `rr replay` cannot open a trace that still holds its `incomplete`
sentinel. Those runs are parked under `<output>/failed/<harness-pid>.<nnnnn>/`
with the same artifacts plus a `FAILED.txt` saying why, `capture_failure` and
`rr_trace` fields in `meta.json`, and they are counted separately as *failed
reproducers*: they do not count toward `--reproducers N`, are never fed to the
reducer, and do not make the harness exit non-zero. Everything directly under
`<output>/` stays re-runnable. A minimised re-run whose trace does not finalise
records `trace_failure` in `<repro>/minimized/meta.json` and files no trace.

To keep those recordings from failing in the first place, each run is launched
through `setsid(1)` when one is available, so it runs in its own session instead
of the harness' process group. The terminal's signals — `SIGINT` on Ctrl+C,
which would kill the very runs a graceful shutdown is waiting on, and the
`SIGWINCH` of a window resize, which is what aborts rr — then never reach the
children. The harness verifies at startup that `setsid` execs in place rather
than forking (a forking one would hide every run's exit status) and falls back
to launching children directly if it does not, warning when `--rr` is on. The
binary used, if any, is recorded in `<output>/<harness-pid>.run-info.txt` as
`detach:`. One consequence: runs no longer die with the terminal, so a harness
killed outright can leave a run to finish on its own.

Naming captures after the harness pid lets several harnesses share one output
directory: each campaign's reproducers stay grouped, and the sequence numbers
order them by age within a campaign. Directories are claimed with a
non-recursive `mkdir()`, which fails rather than reusing an existing name, so a
recycled pid simply continues past whatever numbers are already on disk instead
of writing over them. Per-run work directories are namespaced the same way. The
settings of each campaign are written alongside its captures as
`<output>/<harness-pid>.run-info.txt`, including the `work dir:` it used.

With `--reduce steps` a capture is followed by a binary search for the smallest
`{steps}` value that still reproduces the same failure with the same seed and
port; the minimised re-run's artifacts are written to
`<repro>/minimized/`. The template must contain `{steps}` for this to work, and
the search is bounded by `--reduce-timeout` seconds.

The kernel `core_pattern` must contain `%p` so a core produced by one concurrent
run can be matched to it by PID; the harness aborts at startup otherwise. Pass
`--no-core-check` to bypass that (core collection then becomes best-effort). A
piped `core_pattern` (systemd-coredump and similar) is allowed but cores are not
collected automatically. `--rr` requires the `rr` binary on `PATH`;
`--rr-chaos` implies `--rr` and adds `rr record --chaos`.

A run that exits `78` is treated as a startup failure rather than a finding: the
fuzzer rejected its command line and never executed a command, so every later run
of that template would fail identically. The harness stops the campaign, prints
the fuzzer's own error message and the exact command it was given, and exits
`78` itself — a mistyped option shows up as one legible error instead of an
endless column of failures. Nothing is captured as a reproducer for it. The
harness also refuses to start when the template's fuzzer script does not exist,
so a mistyped path is caught before any run is spawned.

Stop conditions are `--runs N`, `--seconds N`, and `--reproducers N` (any that
are set; unlimited otherwise), or pressing `q`/`Esc`/`Ctrl-C` in the dashboard.
The first stop request drains in-flight runs and then exits; a run that finishes
on its own during that drain is still classified and captured, so a crash landing
in the last moments of a campaign is not thrown away; interrupting again
while that shutdown is running — immediately, or a quick double-tap after the
"still shutting down" hint — abandons the drain, kills whatever is left, and
exits `130`, leaving any stragglers for the OS to reap. A lone interrupt once
the drain has been running a while only re-arms that double-tap and prints the
hint, so a slow-draining run reads differently from a wedged one.
The TUI is used when stdout and stdin are a TTY; otherwise, or with `--no-tui`,
the harness prints a line per finished run and a periodic summary. The dashboard
tallies failures, crashes, timeouts, reproducers, and reductions, plus a `leaks`
count whenever `leaks` is in `--capture`, a `failed repros` count once a capture
has had to be parked under `failed/`, and an `rr aborts` count once rr has died
on a run of its own accord. The process exits non-zero when at least one
reproducer was captured. Point it only at a disposable Redis target.

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

Pass a comma-separated subset to choose randomly from only those values. This
can exclude a known-problematic implementation while still varying the setting;
for example, this selects every serializer except msgpack:

```bash
vendor/bin/phpredis-fuzz --serializer=none,php,igbinary,json
```

CSV subsets work for every option in the table. Unknown names and empty CSV
entries are rejected. Duplicate names are treated as one choice rather than as
weights. Values in the subset that the loaded extension does not support are
not picked, consistent with `random`; the run fails if none are supported.

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
reply-type counts, Redis errors, captured PHP warnings, thrown exceptions,
Relay cache statistics (when a Relay client is present), and
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
concrete generated calls and the selected invocation typing declaration.
`--catch=STRING` stops the workload as soon as a
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

### Stateful scenarios

Named state-machine scenarios are disabled unless selected explicitly. They run
before the random command stream, use the run seed in their key namespace, and
report every operation and postcondition in `stateful_outcomes`:

```bash
vendor/bin/phpredis-fuzz \
    --client=relay-cluster \
    --scenarios=transaction-exec,transaction-discard,watch-unwatch-discard \
    --steps=1000 \
    --seed=123456
```

The built-in scenarios verify transaction commit, discard cleanup, and
`WATCH`/`UNWATCH` cleanup. Each terminal operation asserts that the client is
back in atomic mode; commit also verifies both writes and discard verifies the
key remains absent. A failed postcondition makes the CLI exit nonzero. Clients
missing a required method produce a structured `skipped` outcome.

The `STATEFUL` command flag is separate from scenarios. Standalone random
`MULTI`, `EXEC`, `DISCARD`, `WATCH`, `UNWATCH`, and pipeline commands are
excluded by default; use `--include=stateful` when deliberately mixing them
into the random stream. The `--include` option accepts a comma-separated list
of `admin`, `local`, `flush`, `stateful`, and `crash`; `--include=all` enables
the first four.

`crash` is never enabled by `all` and has to be named on its own. It selects
the deliberately process-crashing catalog entry (`Relay\Relay::crash()`), which
only exists in debug builds of the extension and segfaults the running process
by design. It is useful for verifying that a supervisor such as
`phpredis-fuzz-harness` captures reproducers (cores, `rr` traces) correctly:

```bash
bin/phpredis-fuzz --include=crash --commands=crash --steps=1
```

Because the safety gates and `--commands` filters are independent, a pattern
list that does not match `crash` still excludes it: `--commands='@cached'` with
`--include=crash` never runs it.

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

## Live command statistics

`phpredis-commandstats` is a resize-aware php-tui dashboard for the calls in
`INFO commandstats`. Its first sample is the baseline, so every displayed value
is a cumulative delta since the dashboard started rather than a server lifetime
counter. Commands are sorted from most to least frequent and the dashboard packs
multiple command groups across wider terminals.

```bash
# A standalone server through PhpRedis
vendor/bin/phpredis-commandstats --client=redis --host=127.0.0.1 --port=6379

# Every connected primary and replica in a cluster, sampled through Relay
vendor/bin/phpredis-commandstats \
    --client=relay-cluster \
    --seeds=127.0.0.1:7000,127.0.0.1:7001 \
    --interval=0.5
```

Cluster mode runs `CLUSTER NODES` against the configured seeds, connects to
each advertised node directly, and aggregates command deltas into separate
primary and replica columns. It accepts `redis-cluster` and `relay-cluster`, or
the equivalent `redis`/`relay` with `--cluster`. Press `q`, Esc, or Ctrl-C to
leave the dashboard. Unreachable servers and partial or complete cluster
outages are shown in the dashboard without terminating it; connections and
topology discovery are retried every interval, and recovered nodes resume from
their prior counters (or establish a new baseline when first discovered). Run
`vendor/bin/phpredis-commandstats --help` for all connection options.

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
`--client=relay-cluster`. When a run does not select a Relay cluster client,
supplied Relay cluster options are ignored with a warning so the same invocation
can be reused while switching between standalone and cluster clients.

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
| `saturateChance` | `0.0` | Chance from `0.0` to `1.0` of running a Relay cache-saturation event after a fuzz step |
| `saturateSteps` | `null` | Maximum whole-key reads per saturation event; `null` makes one complete key-space pass. `Fast` mode requires it and splits `saturateTarget` into that many bitmaps |
| `saturateTarget` | `null` | Target Relay `memory.used` byte count; overrides `saturateSteps` and stops after one key-space pass if unmet |
| `saturateMode` | `SaturationMode::Natural` | `Natural` reads existing values; `Seeded` writes generated values before reading them; `Fast` grows one `SETBIT` bitmap per step and reads it back, and requires `saturateTarget` and `saturateSteps` |
| `invocationMode` | `InvocationMode::Strict` | Typing mode used at the client method-call boundary |
| `commands` | `[]` | Command name, glob, and flag filters |
| `weights` | `[]` | Command or flag weights |
| `raw` | `false` | Enable raw-protocol paths and raw commands |
| `rawChaos` | `false` | Enable raw-protocol calls with a real command name and arbitrary scalar arguments |
| `includeBlocking` | `false` | Include blocking commands |
| `includeLocal` | `false` | Include client-local state changes, including runtime option mutation and Relay cluster slot-cache invalidation |
| `includeAdmin` | `false` | Include administrative commands |
| `includeFlush` | `false` | Include `FLUSHDB` and `FLUSHALL` |
| `includeCrashing` | `false` | Include deliberately crashing commands (CLI: `--include=crash`, never enabled by `--include=all`) |
| `scriptLog` | `null` | Optional executable reproduction script path |
| `catchPattern` | `null` | Case-insensitive Redis error, warning, or exception substring that stops the run after a match |
| `differential` | `false` | Enable ordered reference-versus-Relay cache-read comparisons |
| `differentialToleranceMs` | `10.0` | Maximum time for a mismatched Relay read to converge before it is divergent |
| `differentialPollIntervalMs` | `1.0` | Delay between Relay convergence attempts |
| `scenarios` | `[]` | Named seeded state-machine scenarios to run before random steps |
| `includeStateful` | `false` | Include standalone stateful commands in random fuzzing |

At least one of `maxSteps` or `maxSeconds` must be greater than zero.

Command filters are case-insensitive:

- `get` matches one command.
- `get*` uses shell-style glob matching.
- `@read` selects commands with the read flag.
- `-getex` or `-@scan` excludes a name or flag.
- Positive filters are ORed, then negative filters are removed.

Supported flags are `read`, `write`, `delete`, `flush`, `blocking`, `cached`,
`invalidating`, `expire`, `raw`, `select`, `admin`, `scan`, `local`, `crash`,
`crossslot`, and `stateful`. Safety category switches are applied after name filters, so
explicitly naming `flushall` still requires `includeFlush: true`.

Weights use command names or flags:

```php
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;

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
directly should call `FuzzConfig::beginCommand($command)` once per normal
command, or `FuzzConfig::beginCommand($command, raw: true)` before calling
`fuzzRaw()`. The raw form also prepares the routing key required by
`RedisCluster::rawCommand()` and `Relay\Cluster::rawCommand()`. Without a scope,
every generated key picks its own tag
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
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;

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
key through normal client methods produce a valid request regardless, so the
number of `CROSSSLOT` errors returned can be lower than this count. For raw
cluster calls, the forced policy deliberately chooses a routing tag different
from the first generated key, allowing a one-key command to exercise the
wrong-node path too.

The runner seeds PHP's process-global Mersenne Twister because the extracted
command implementations use `rand()`/`mt_rand()`. The selected seed and concrete
arguments are reproducible when the same PHP/client versions, server topology,
configuration, and initial Redis state are used. Avoid unrelated calls to
`rand()` in the same process while a run is active.

## Relay cache saturation

Cache saturation is an opt-in Relay-only workload that runs alongside normal
fuzz steps. After each normal step, `saturateChance` is rolled using the seeded
workload RNG. A successful roll reads complete values from the fuzzer's known
key namespaces through a randomly selected `Relay\Relay` or `Relay\Cluster`
client:

- strings with `GET`
- lists with `LRANGE key 0 -1`
- sets with `SMEMBERS`
- hashes with `HGETALL`
- sorted sets with `ZRANGE key 0 -1 WITHSCORES`

The default `natural` mode only performs those reads. The `seeded` mode first
writes the selected key with the corresponding normal command (`SET`, `RPUSH`,
`SADD`, `HSET`, or `ZADD`) using the configured string lengths, member limit,
serializer, compression, and prefix behavior, then immediately performs the
whole-value read. Seeded mode intentionally mutates only the fuzzer's known key
namespaces and is more efficient when the goal is to fill an otherwise sparse
Relay cache.

The `fast` mode ignores the generated key space and the read table above. Each
step grows one dedicated bitmap with `SETBIT key offset 1` and immediately reads
the whole value back with `GET`, which is the cheapest deterministic way to move
a known number of bytes into Relay's cache. It is the right mode for exercising
eviction, where the goal is to reach `relay.maxmemory` quickly and repeatedly
rather than to exercise value shapes.

No `SCAN`, `KEYS`, or `TYPE` calls are needed. Standalone passes cover each of
the five type namespaces across `keys`; cluster passes additionally cover every
configured hash tag from `shards`. The client prefix, serializer, and
compression settings continue to apply normally.

When `saturateSteps` is `null`, one event makes a complete pass. When it is set,
the event performs at most that many reads and the next event resumes at the
following key. `saturateTarget` overrides that step limit: Relay's global
`Relay\Relay::stats()['memory']['used']` value is checked before the event and
after every read, and the event stops once it reaches the requested byte count.
If the target cannot be reached, the event ends after one complete pass over the
known key space. Saturation reads do not consume the normal `maxSteps` budget,
but the wall-clock `maxSeconds` deadline can truncate a batch. At least one Relay
client is required when the chance is nonzero. A selected client that is
currently in a transaction or pipeline is skipped so saturation reads cannot
alter its queued workload.

`fast` mode reads `saturateSteps` differently: it is the granularity of one
event, not a per-event cap. The target is divided by the step count, rounding
up, and each step writes and reads one bitmap of that size, so a 10 MiB target
with 10 steps writes ten 1 MiB keys and the same target with 100 steps writes a
hundred 100 KiB keys. Both `saturateTarget` and `saturateSteps` are therefore
required; a run without them is rejected before connecting. The bitmap keys are
named `saturate:<bytes>:<index>`, outside every generated key namespace, and
cluster runs tag them as `saturate:{0}:<bytes>:0`, `saturate:{1}:<bytes>:1`, ...
so a pass spreads across the configured shards. The size is part of the name
because `SETBIT` never shrinks a string, so a smaller chunk would otherwise
inherit whatever a larger earlier run left on the server. Every event restarts
at the first key, so the server-side key space stays bounded and a run refills
the same keys after Relay evicts them. Memory is still checked before the event and after every
step, so an event that starts at or above the target does no work at all.

What a `fast` event actually costs in cache bytes depends on Relay's own
configuration, notably `relay.maxmemory`, `relay.maxmemory_pct`, and
`relay.eviction_strategy`: a target above the eviction threshold will keep
evicting and refilling instead of settling, which is usually the point when
testing eviction. A serializer or compressor is still applied to the `GET`,
which sees raw bitmap bytes it did not write, so `fast` runs against a
serializing client will report the corresponding client warnings or errors.

```bash
bin/phpredis-fuzz --client=relay --steps=100000 \
    --saturate-chance=0.05 --saturate-mode=fast \
    --saturate-target=16m --saturate-steps=10
```

```php
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use Mgrunder\PhpredisCommandFuzzer\SaturationMode;

$configuration = new RunConfiguration(
    maxSteps: 100000,
    saturateChance: 0.02,
    saturateTarget: 100 * 1024 * 1024,
    saturateMode: SaturationMode::Seeded,
);
```

```bash
bin/phpredis-fuzz --client=relay --steps=100000 \
    --saturate-chance=0.02 --saturate-target=100m --saturate-mode=seeded
```

The CLI accepts an integer byte count, a case-insensitive `k`, `m`, `g`, or `t`
suffix, or a percentage from greater than `0%` through `100%`. Suffixes use
powers of 1024, so `100m` is 104,857,600 bytes. A percentage such as `95.2%` is
resolved once at startup against `Relay\Relay::stats()['memory']['total']` and
rounded to the nearest byte. The resolved byte count is recorded in the run
configuration and reproduction log.

```bash
bin/phpredis-fuzz --client=relay --steps=100000 \
    --saturate-chance=0.02 --saturate-target=95.2% --saturate-mode=seeded
```

The result includes `saturation_events`, `saturation_reads`, and detailed
`saturation_outcomes`. Saturation calls also pass through the standard warning,
Redis-error, exception, catch-pattern, and reproduction-script machinery.

## Development

```bash
composer install
find src tests bin -type f -exec php -l {} +
vendor/bin/phpstan analyse --debug --no-progress
vendor/bin/phpunit
bin/phpredis-fuzz --help
bin/phpredis-fuzz-harness --help
bin/phpredis-commands --help
bin/phpredis-coverage --help
bin/phpredis-commandstats --help
```

PHPStan runs at level `max`. PHPUnit unit tests do not require a Redis server.
Run live integration checks only against an explicitly selected disposable
standalone instance or cluster.
