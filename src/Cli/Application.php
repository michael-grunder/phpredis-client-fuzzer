<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookLoader;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\InvocationMode;
use Mgrunder\PhpredisCommandFuzzer\Log\Log;
use Mgrunder\PhpredisCommandFuzzer\OptionChoices;
use Mgrunder\PhpredisCommandFuzzer\RelayClusterOptions;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use Mgrunder\PhpredisCommandFuzzer\SaturationMode;
use Mgrunder\PhpredisCommandFuzzer\SaturationTarget;

final class Application
{
    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /**
     * @param resource|null $output Defaults to STDOUT.
     * @param resource|null $error Defaults to STDERR.
     */
    public function __construct(
        $output = null,
        $error = null,
        private readonly InvocationMode $defaultInvocationMode = InvocationMode::Strict,
    )
    {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    private const VALUE_OPTIONS = [
        'client', 'host', 'port', 'seeds', 'username', 'password', 'timeout',
        'read-timeout', 'serializer', 'compression', 'prefix', 'steps',
        'seconds', 'seed', 'forks', 'commands', 'weight', 'keys', 'members', 'shards',
        'min-length', 'max-length', 'max-command-keys', 'max-prefix-length',
        'wrongtype-chance', 'crossslot-chance', 'saturate-chance',
        'saturate-steps', 'saturate-target', 'saturate-mode', 'script-log', 'catch',
        'invocation-mode', 'include',
        'differential-tolerance-ms', 'differential-poll-ms',
        'scenarios', 'hook',
        'output',
        'relay-failover', 'relay-distribute', 'relay-node-read-timeout',
        'relay-multikey-reordering',
    ];

    private const FLAG_OPTIONS = [
        'help', 'verbose', 'raw', 'raw-chaos', 'include-blocking',
        'no-relay-compatibility', 'differential',
    ];

    private const REPEATABLE_OPTIONS = ['weight', 'hook'];

    private const RELAY_CLUSTER_OPTIONS = [
        'relay-failover',
        'relay-distribute',
        'relay-node-read-timeout',
        'relay-multikey-reordering',
    ];

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        // Everything up to the point this flips is command-line handling: a
        // throwable before it means the run never had a chance to start, and
        // is reported with ExitCode::STARTUP so a supervisor can tell "this
        // invocation is broken" from "this run found something".
        $configured = false;

        try {
            $options = Options::parse(
                $arguments,
                self::VALUE_OPTIONS,
                self::FLAG_OPTIONS,
                self::REPEATABLE_OPTIONS,
            );

            if ($options->has('help')) {
                $this->write($this->help());
                return 0;
            }

            $outputMode = OutputMode::parse($options->string('output', 'json'));
            $invocationMode = InvocationMode::parse(
                $options->string('invocation-mode', $this->defaultInvocationMode->value),
            );
            $saturationMode = SaturationMode::parse(
                $options->string('saturate-mode', SaturationMode::Natural->value),
            );
            $saturationTargetValue = $options->nullableString('saturate-target');
            $saturationTarget = $saturationTargetValue === null
                ? null
                : SaturationTarget::parse($saturationTargetValue);
            if ($saturationMode === SaturationMode::Fast
                && ($saturationTarget === null
                    || $options->optionalInteger('saturate-steps') === null)) {
                throw new \InvalidArgumentException(
                    '--saturate-mode=fast requires --saturate-target and --saturate-steps',
                );
            }
            $includes = IncludeCategories::parse($options->nullableString('include'));

            if ($options->has('verbose')) {
                Log::setLogger(function (string $level, string $message, array $context): void {
                    $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_THROW_ON_ERROR);
                    $this->write("[{$level}] {$message}{$suffix}\n", true);
                });
            }

            $clientTypes = ClientTypeParser::parse($options->string('client', 'redis'));
            $host = $options->string('host', '127.0.0.1');
            $port = $options->integer('port', 6379);
            $seeds = Options::split($options->string('seeds', "{$host}:{$port}"));
            if ($seeds === []) {
                throw new \InvalidArgumentException('--seeds cannot be empty');
            }

            $username = $options->nullableString('username');
            $password = $options->nullableString('password');
            $auth = $username !== null && $password !== null
                ? [$username, $password]
                : $password;

            // Resolve the seed before anything random so that "pick a setting
            // for me" options are reproducible along with the command stream.
            // Fuzzer::run() re-seeds with the same value, so a run that chooses
            // its settings randomly executes the same commands as one given
            // those settings by name.
            $seed = $options->optionalInteger('seed') ?? random_int(0, PHP_INT_MAX);
            mt_srand($seed);

            $forks = $options->integer('forks', 0);
            if ($forks < 0 || $forks > ForkPool::MAX_FORKS) {
                throw new \InvalidArgumentException(
                    '--forks must be between 0 and ' . ForkPool::MAX_FORKS,
                );
            }
            if ($forks > 0 && !ForkPool::supported()) {
                throw new \RuntimeException('--forks requires the pcntl extension');
            }

            $hooks = HookLoader::load($options->repeated('hook'));

            $hasRelayClient = in_array(ClientType::Relay, $clientTypes, true)
                || in_array(ClientType::RelayCluster, $clientTypes, true);
            $hasRelayCluster = in_array(ClientType::RelayCluster, $clientTypes, true);

            if ($saturationTarget?->isPercentage() && !$hasRelayClient) {
                throw new \InvalidArgumentException(
                    'A percentage --saturate-target requires a Relay client',
                );
            }

            if (!$hasRelayCluster) {
                foreach (self::RELAY_CLUSTER_OPTIONS as $name) {
                    if ($options->has($name)) {
                        $this->write(
                            "Warning: --{$name} only applies to --client=relay-cluster, ignoring\n",
                            true,
                        );
                    }
                }
                $relayCluster = new RelayClusterOptions();
            } else {
                $relayCluster = new RelayClusterOptions(
                    failover: $this->choice($options, 'relay-failover', OptionChoices::relayFailover(), 'failover'),
                    distribute: $this->choice($options, 'relay-distribute', OptionChoices::relayDistribute(), 'distribute'),
                    nodeReadTimeout: $options->optionalNumber('relay-node-read-timeout'),
                    multikeyReordering: $this->choice(
                        $options,
                        'relay-multikey-reordering',
                        OptionChoices::relayMultikeyReordering(),
                        'multikey reordering',
                    ),
                );
            }

            $serializer = OptionChoices::resolve(
                $options->string('serializer', 'none'),
                OptionChoices::SERIALIZER,
                'serializer',
            );
            $compression = OptionChoices::resolve(
                $options->string('compression', 'none'),
                OptionChoices::COMPRESSION,
                'compression',
            );

            $clientConfigurations = [];
            foreach ($clientTypes as $type) {
                $clientConfigurations[] = new ClientConfiguration(
                    type: $type,
                    host: $host,
                    port: $port,
                    seeds: $seeds,
                    timeout: $options->number('timeout', 1.0),
                    readTimeout: $options->number('read-timeout', 1.0),
                    auth: $auth,
                    prefix: $options->string('prefix', ''),
                    serializer: $serializer,
                    compression: $compression,
                    relayCompatibility: !$options->has('no-relay-compatibility'),
                    relayCluster: $type === ClientType::RelayCluster
                        ? $relayCluster
                        : new RelayClusterOptions(),
                );
            }

            // Built through a factory because a forked run gives every child
            // its own seed and its own reproduction script; everything else is
            // shared, and validation still happens here, before the fork.
            $scriptLog = $options->nullableString('script-log');
            $configure = fn (int $runSeed, ?string $runScriptLog): RunConfiguration => new RunConfiguration(
                maxSteps: $options->integer('steps', 100),
                maxSeconds: $options->number('seconds', 0.0),
                seed: $runSeed,
                keys: $options->integer('keys', 100),
                shards: $options->integer('shards', 16),
                members: $options->integer('members', 10),
                minLength: $options->integer('min-length', 4),
                maxLength: $options->integer('max-length', 32),
                maxKeysPerCommand: $options->integer('max-command-keys', 10),
                maxPrefixLength: $options->integer('max-prefix-length', 0),
                wrongTypeChance: $options->number('wrongtype-chance', 0.0),
                crossSlotChance: $options->number('crossslot-chance', 0.0),
                saturateChance: $options->number('saturate-chance', 0.0),
                saturateSteps: $options->optionalInteger('saturate-steps'),
                saturateTarget: $saturationTarget?->resolve(),
                saturateMode: $saturationMode,
                commands: $options->csv('commands'),
                weights: $this->weights($options->repeated('weight')),
                raw: $options->has('raw'),
                includeBlocking: $options->has('include-blocking'),
                includeLocal: $includes->local,
                includeAdmin: $includes->admin,
                includeFlush: $includes->flush,
                includeCrashing: $includes->crash,
                includeStateful: $includes->stateful,
                scriptLog: $runScriptLog,
                catchPattern: $options->nullableString('catch'),
                differential: $options->has('differential'),
                differentialToleranceMs: $options->number('differential-tolerance-ms', 10.0),
                differentialPollIntervalMs: $options->number('differential-poll-ms', 1.0),
                scenarios: $options->csv('scenarios'),
                invocationMode: $invocationMode,
                rawChaos: $options->has('raw-chaos'),
            );

            $runConfiguration = $configure($seed, $scriptLog);

            // Every child of a forked run gets its own seed, drawn from the
            // reported one, so the processes fuzz different command streams
            // while the whole run stays reproducible from --seed and --forks.
            $childSeeds = [];
            for ($index = 0; $index < $forks; $index++) {
                $childSeeds[] = mt_rand(0, mt_getrandmax());
            }

            // The command line has been fully parsed and validated; from here
            // on a failure is a property of the target or the workload.
            $configured = true;

            $factory = new ClientFactory($hooks);
            $clients = [];
            foreach ($clientConfigurations as $clientConfiguration) {
                $clients[] = $factory->create($clientConfiguration);
            }

            if ($forks === 0) {
                return $this->execute($clients, $runConfiguration, $hooks, $outputMode, $this->output);
            }

            return (new ForkPool($this->output, $this->error))->run(
                $forks,
                /** @param resource $output */
                function (int $index, $output) use (
                    $clients,
                    $clientConfigurations,
                    $factory,
                    $childSeeds,
                    $configure,
                    $scriptLog,
                    $hooks,
                    $outputMode,
                ): int {
                    // Redis and RedisCluster cannot be used after a fork: the
                    // child inherits the parent's socket. Relay and
                    // Relay\Cluster carry their own fork machinery, and
                    // exercising it is the point of --forks, so those clients
                    // are used exactly as inherited.
                    foreach ($clients as $position => $client) {
                        if ($client instanceof \Redis || $client instanceof \RedisCluster) {
                            $clients[$position] = $factory->create($clientConfigurations[$position]);
                        }
                    }

                    return $this->execute(
                        $clients,
                        $configure(
                            $childSeeds[$index],
                            $this->childScriptLog($scriptLog, $index),
                        ),
                        $hooks,
                        $outputMode,
                        $output,
                    );
                },
            );
        } catch (\Throwable $throwable) {
            $this->write('phpredis-fuzz: ' . $throwable->getMessage() . "\n", true);
            return $configured ? ExitCode::FAILURE : ExitCode::STARTUP;
        }
    }

    /**
     * Runs one workload and writes its formatted result.
     *
     * @param list<\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster> $clients
     * @param resource $output Where the result is written; a forked child
     *        writes to its own socket instead of the shared output stream.
     */
    private function execute(
        array $clients,
        RunConfiguration $configuration,
        HookRegistry $hooks,
        OutputMode $outputMode,
        $output,
    ): int {
        $result = (new Fuzzer($hooks))->run($clients, $configuration);

        fwrite($output, (new ResultFormatter())->format($result, $outputMode));

        return $result->caughtDiagnostic === null
            && !$result->hasDifferentialDivergence()
            && !$result->hasStatefulFailure()
            ? ExitCode::SUCCESS
            : ExitCode::FAILURE;
    }

    /**
     * Gives each forked child its own reproduction script so concurrent
     * children never overwrite one another's evidence.
     */
    private function childScriptLog(?string $path, int $index): ?string
    {
        if ($path === null) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension === '') {
            return $path . '.' . ($index + 1);
        }

        return substr($path, 0, -(strlen($extension) + 1)) . '.' . ($index + 1) . '.' . $extension;
    }

    /**
     * Reads an optional value option that names one of a fixed set of
     * settings, expanding the random sentinel into a concrete name.
     *
     * @param array<string, string> $choices Setting name to constant.
     */
    private function choice(Options $options, string $name, array $choices, string $kind): ?string
    {
        $value = $options->nullableString($name);

        return $value === null ? null : OptionChoices::resolve($value, $choices, $kind);
    }

    /**
     * @param list<string> $values
     * @return array<string, float>
     */
    private function weights(array $values): array
    {
        $weights = [];
        foreach ($values as $value) {
            if (!preg_match('/^(@?[a-zA-Z_]+):([0-9]+(?:\.[0-9]+)?)$/', $value, $matches)) {
                throw new \InvalidArgumentException("Invalid weight: " . var_export($value, true));
            }
            $weights[strtolower($matches[1])] = (float) $matches[2];
        }
        return $weights;
    }

    private function write(string $message, bool $error = false): void
    {
        $stream = $error ? $this->error : $this->output;
        fwrite($stream, $message);
    }

    private function help(): string
    {
        return str_replace(
            '{invocation-default}',
            $this->defaultInvocationMode->value,
            self::HELP,
        );
    }

    private const HELP = <<<'HELP'
phpredis-fuzz - fuzz PhpRedis-compatible clients against a disposable Redis target

Usage:
  phpredis-fuzz [options]

Clients and connection:
  --client=TYPE[:COUNT],...  redis, redis-cluster, relay, relay-cluster (default: redis)
                             COUNT creates that many client instances (default: 1;
                             maximum total: 10000)
  --host=HOST                Standalone host (default: 127.0.0.1)
  --port=PORT                Standalone port (default: 6379)
  --seeds=HOST:PORT,...      Cluster seeds (defaults to host and port)
  --username=USER            ACL username
  --password=PASS            Password
  --timeout=SECONDS          Connect timeout (default: 1)
  --read-timeout=SECONDS     Read timeout (default: 1)
  --serializer=NAME[,NAME]   none, php, igbinary, msgpack, json, or random
  --compression=NAME[,NAME]  none, lzf, zstd, lz4, or random
  --prefix=PREFIX            Client key prefix
  --no-relay-compatibility   Disable Relay PhpRedis compatibility

Relay cluster options (relay-cluster only; each is verified via setOption()):
  --relay-failover=MODE[,MODE]
                             Cluster::OPT_FAILOVER retry strategy:
                             none, primary, random_replica, replicas, all,
                             or random
  --relay-distribute=MODE[,MODE]
                             Cluster::OPT_DISTRIBUTE readonly distribution:
                             none, random, random_replica, replicas, all;
                             use any to choose a mode at random, since random
                             is itself a distribution mode
  --relay-node-read-timeout=SECONDS
                             Cluster::OPT_NODE_READ_TIMEOUT per-node read
                             timeout override; 0 disables the override
  --relay-multikey-reordering=MODE[,MODE]
                             Cluster::OPT_MULTIKEY_REORDERING slot grouping:
                             none, reads, writes, all, or random

Run configuration:
  --steps=N                  Maximum executed operations (default: 100)
  --seconds=N                Maximum duration; 0 disables (default: 0)
  --seed=N                   Reproducible random seed (generated when omitted)
  --forks=N                  Run the workload in N pcntl_fork()ed children
                             instead of this process; 0 does not fork
                             (default: 0). Each child runs the full --steps or
                             --seconds workload with its own seed derived from
                             --seed, re-creating Redis and RedisCluster clients
                             because those cannot be used after a fork, and
                             using inherited Relay clients as-is. The parent
                             only supervises: it prints each child's result
                             whole as that child finishes, waits for all of
                             them, and exits with the most severe child status.
                             --script-log gets a per-child suffix.
  --commands=PATTERN,...     Include/exclude names, globs, or @flags
  --weight=TARGET:N          Set command or @flag weight; repeatable
  --keys=N                   Generated key space (default: 100)
  --members=N                Maximum generated members (default: 10)
  --shards=N                 Generated cluster hash tags (default: 16)
  --min-length=N             Minimum generated string length (default: 4)
  --max-length=N             Maximum generated string length (default: 32)
  --max-command-keys=N       Maximum keys in one command (default: 10)
  --max-prefix-length=N      Maximum random prefix length (default: 0)
  --wrongtype-chance=N       Probability from 0 to 1 of using a wrong key type (default: 0)
  --crossslot-chance=N       Probability from 0 to 1 that a single-slot command
                             gets keys in different cluster slots, forcing a
                             CROSSSLOT error (cluster only, default: 0)
  --saturate-chance=N        Probability from 0 to 1 of running a Relay cache
                             saturation event after a fuzz step (default: 0)
  --saturate-steps=N         Maximum whole-key reads per saturation event;
                             omitted means one pass over the known key space.
                             With --saturate-mode=fast it is required and splits
                             the target into that many bitmaps
  --saturate-target=TARGET   Read until Relay memory.used reaches a byte SIZE
                             (K/M/G/T suffixes use powers of 1024) or a percent
                             of Relay memory.total, such as 95.2%; overrides
                             steps and stops after one known-key-space pass
  --saturate-mode=MODE       natural reads existing keys; seeded writes generated
                             values before reading each key; fast grows one
                             SETBIT bitmap per step and reads it back, filling
                             the target quickly (default: natural).
                             fast requires --saturate-target and --saturate-steps
  --invocation-mode=MODE     Client call typing: strict or coercive
                             (default: {invocation-default})
  --script-log=FILE          Write an executable PHP reproduction script
  --hook=FILE                Load trusted PHP invocation and lifecycle hooks;
                             repeatable
  --catch=STRING             Stop after a Redis error, warning, or exception
                             contains STRING (case-insensitive; exits nonzero)
  --differential             Compare cacheable reads using an ordered pair:
                             --client=redis,relay or redis-cluster,relay-cluster
  --differential-tolerance-ms=N
                             Wait up to N ms for a transient mismatch to converge
                             before reporting a divergence (default: 10)
  --differential-poll-ms=N   Delay between convergence attempts (default: 1)
  --output=MODE              json, simple, or detailed (default: json)
  --raw                      Enable raw-protocol command paths
  --raw-chaos                Enable rawCommand calls with arbitrary scalar arguments
  --include-blocking         Enable blocking commands
  --include=CATEGORY,...     Enable opt-in command categories: admin, local,
                             flush, stateful, crash, or all (all enables the
                             first four; crash is never enabled by all and must
                             be named explicitly)
  --scenarios=NAME,...       Run seeded scenarios first: transaction-exec,
                             transaction-discard, watch-unwatch-discard,
                             none, or random (random may select any subset)
  --verbose                  Log command execution to stderr
  --help                     Show this help without connecting to Redis

Choosing a setting at random:
  Options with a fixed list of values also accept random (or any, which never
  collides with a value named random). One supported value is picked before
  the run using the same seed as the workload, so repeating the run with the
  reported --seed reproduces both the chosen settings and the commands. Values
  the loaded extension does not support are never picked. Pass a comma-separated
  subset, such as --serializer=none,php,igbinary,json, to choose randomly from
  only those values.

Hooks:
  Hook files are trusted PHP code and must return a callable accepting a
  HookRegistry, an InvocationHook object, or a lifecycle hook object.
  Invocation hooks run after generated ScriptArg values are resolved and before
  reproduction logging or client dispatch, and may allow, reject, or replace
  the call. See hooks/no-msgpack.php.

  Lifecycle hooks only observe, and are registered with addLifecycleHook() or
  the onPostConstructor(), onPreCommand(), onPostCommand(), and
  onPreDestructor() registry methods:
    postConstructor  once per client object, before the fuzzer uses it
    preCommand       before each client call, with the final arguments
    postCommand      after each client call, with its reply or throwable
    preDestructor    when the fuzzer is done with a client, while it is still
                     connected and usable
  See hooks/lifecycle-example.php.

Exit status:
  0   the run finished and nothing was caught
  1   a caught diagnostic, a differential divergence, or a failed scenario
  78  startup failure: the command line was rejected or describes an unusable
      configuration, and no command was executed. phpredis-fuzz-harness stops a
      campaign when a run exits this way.

The target is mutated. Use only an explicitly selected disposable Redis instance.
HELP;
}
