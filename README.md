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

## CLI

Composer installs `vendor/bin/phpredis-fuzz`. Its generic `--client` option
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

CLI output is JSON and includes the seed, PHP/client versions, client classes and
topology, effective configuration, elapsed time, selected commands, reply-type
counts, captured PHP warnings, and thrown exceptions. `--script-log`
writes an executable PHP reproduction script containing the concrete generated
calls.

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
| `commands` | `[]` | Command name, glob, and flag filters |
| `weights` | `[]` | Command or flag weights |
| `raw` | `false` | Enable raw-protocol paths and raw commands |
| `includeBlocking` | `false` | Include blocking commands |
| `includeLocal` | `false` | Include client-local state changes |
| `includeAdmin` | `false` | Include administrative commands |
| `includeFlush` | `false` | Include `FLUSHDB` and `FLUSHALL` |
| `includeCrashing` | `false` | Include deliberately crashing commands |
| `scriptLog` | `null` | Optional executable reproduction script path |

At least one of `maxSteps` or `maxSeconds` must be greater than zero.

Command filters are case-insensitive:

- `get` matches one command.
- `get*` uses shell-style glob matching.
- `@read` selects commands with the read flag.
- `-getex` or `-@scan` excludes a name or flag.
- Positive filters are ORed, then negative filters are removed.

Supported flags are `read`, `write`, `delete`, `flush`, `blocking`, `cached`,
`invalidating`, `expire`, `raw`, `select`, `admin`, `scan`, `local`, and
`crash`. Safety category switches are applied after name filters, so explicitly
naming `flushall` still requires `includeFlush: true`.

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
```

PHPStan runs at level `max`. PHPUnit unit tests do not require a Redis server.
Run live integration checks only against an explicitly selected disposable
standalone instance or cluster.
