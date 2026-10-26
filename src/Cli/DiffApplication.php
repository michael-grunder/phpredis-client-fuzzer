<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Cli;

use Mgrunder\RedisClientTester\Differential\CaseGenerator;
use Mgrunder\RedisClientTester\Differential\DifferentialExecutor;
use Mgrunder\RedisClientTester\Differential\DiffRuntime;
use Mgrunder\RedisClientTester\Differential\GeneratorOptions;
use Mgrunder\RedisClientTester\Differential\Minimizer;
use Mgrunder\RedisClientTester\Differential\ReproducerWriter;
use Mgrunder\RedisClientTester\Differential\Rng;
use Mgrunder\RedisClientTester\Differential\RunPair;
use Mgrunder\RedisClientTester\Differential\SignatureIndex;
use Mgrunder\RedisClientTester\Differential\TestCase;

/**
 * Entry point for `bin/redis-client-tester-diff`: the stateful differential fuzzer
 * that runs identical command sequences through PhpRedis and Relay from
 * identical Redis state and reports any difference in replies or resulting
 * state.
 *
 * Exit status: 0 when no divergence was found, 1 when one was (or the run
 * failed, including an unreachable target), and {@see ExitCode::STARTUP} when
 * the command line or the loaded extensions are unusable, which is what
 * `redis-client-tester-harness` expects.
 *
 * @phpstan-import-type Server from DiffRuntime
 */
final class DiffApplication
{
    private const VALUE_OPTIONS = [
        'tls-options',
        'host', 'port', 'username', 'password', 'timeout', 'read-timeout', 'seed', 'cases', 'seconds',
        'steps', 'keys', 'members', 'wrongtype-chance', 'missing-chance', 'edge-chance', 'txn-chance',
        'ttl-chance', 'max-value-bytes', 'serializer', 'compression', 'relay-cache', 'order', 'commands',
        'self-check-every', 'ttl-tolerance-ms', 'output', 'minimize-seconds', 'worker', 'prefix-root',
        'replay', 'minimize-case',
    ];

    private const FLAG_OPTIONS = ['tls', 'help', 'strict-messages', 'no-dedupe', 'allow-flush', 'no-minimize', 'keep-going', 'verbose', 'fail-on-unstable', 'list-commands'];

    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /**
     * The case being executed, so a client fatal error (which no handler can
     * catch) still leaves a reproducer behind.
     *
     * @var array{case: TestCase, writer: ReproducerWriter, executor: DifferentialExecutor, seed: int, options: GeneratorOptions, save: bool}|null
     */
    private ?array $current = null;

    /**
     * @param resource|null $output
     * @param resource|null $error
     */
    public function __construct($output = null, $error = null)
    {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = Options::parse($arguments, self::VALUE_OPTIONS, self::FLAG_OPTIONS, ['commands']);
            if ($options->has('help')) {
                fwrite($this->output, self::HELP);
                return ExitCode::SUCCESS;
            }
            if ($options->has('list-commands')) {
                foreach (CaseGenerator::CATALOG as $name => [$group]) {
                    fwrite($this->output, sprintf("%-18s @%s\n", $name, $group));
                }
                return ExitCode::SUCCESS;
            }
            $config = $this->configure($options);
        } catch (\Throwable $throwable) {
            fwrite($this->error, 'redis-client-tester-diff: ' . $throwable->getMessage() . "\n");
            return ExitCode::STARTUP;
        }

        try {
            return $this->execute($options, ...$config);
        } catch (\Throwable $throwable) {
            fwrite($this->error, 'redis-client-tester-diff: ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n");
            return ExitCode::FAILURE;
        }
    }

    /**
     * Validate the command line and the loaded extensions: anything that would
     * make every run fail the same way is a startup error for the harness.
     * Connecting happens later, because an unreachable target is an ordinary
     * failure that may well pass on the next run.
     *
     * @return array{0: Server, 1: GeneratorOptions, 2: DifferentialExecutor}
     */
    private function configure(Options $options): array
    {
        if (!class_exists('Redis')) {
            throw new \RuntimeException('the redis (PhpRedis) extension is not loaded');
        }
        if (!class_exists('Relay\\Relay')) {
            throw new \RuntimeException('the relay extension is not loaded');
        }

        $username = $options->nullableString('username');
        $password = $options->nullableString('password');
        $auth = $password === null ? null : ($username === null ? $password : [$username, $password]);
        $port = $options->integer('port', 6379);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('--port must be between 1 and 65535');
        }
        $tolerance = $options->integer('ttl-tolerance-ms', 2000);
        if ($tolerance < 0) {
            throw new \InvalidArgumentException('--ttl-tolerance-ms cannot be negative');
        }

        $tls = $options->tls();
        /** @var Server $server */
        $server = [
            'host' => $tls->endpoint($options->string('host', '127.0.0.1')),
            'tls_options' => $tls->options,
            'port' => $port,
            'timeout' => max(0.0, $options->number('timeout', 2.0)),
            'read_timeout' => max(0.0, $options->number('read-timeout', 5.0)),
            'auth' => $auth,
            'ttl_tolerance_ms' => $tolerance,
            'strict_messages' => $options->has('strict-messages'),
        ];

        $serializers = $this->choices($options->csv('serializer', 'none'), 'SERIALIZER', ['none', 'php', 'igbinary', 'msgpack', 'json', 'phpser']);
        $compressions = $this->choices($options->csv('compression', 'none'), 'COMPRESSION', ['none', 'lzf', 'zstd', 'lz4']);
        $relayCache = match ($options->string('relay-cache', 'on')) {
            'on' => [true],
            'off' => [false],
            'random' => [true, false],
            default => throw new \InvalidArgumentException('--relay-cache must be on, off, or random'),
        };
        if ($relayCache !== [true] && !defined('Relay\\Relay::OPT_USE_CACHE')) {
            throw new \RuntimeException('--relay-cache needs Relay::OPT_USE_CACHE, which this Relay lacks');
        }

        $commands = $options->has('commands') ? CaseGenerator::resolveFilter($options->repeated('commands')) : null;
        if ($commands !== null && $options->has('allow-flush')) {
            $commands = array_values(array_unique([...$commands, 'flushdb', 'flushall']));
        }

        $generator = new GeneratorOptions(
            steps: $options->integer('steps', 20),
            keys: $options->integer('keys', 6),
            members: $options->integer('members', 8),
            wrongTypeChance: $options->number('wrongtype-chance', 0.08),
            missingChance: $options->number('missing-chance', 0.08),
            edgeChance: $options->number('edge-chance', 0.15),
            txnChance: $options->number('txn-chance', 0.05),
            ttlChance: $options->number('ttl-chance', 0.15),
            maxValueBytes: $options->optionalByteSize('max-value-bytes') ?? 1024,
            serializers: $serializers,
            compressions: $compressions,
            relayCache: $relayCache,
            order: $options->string('order', 'random'),
            allowFlush: $options->has('allow-flush'),
            commands: $commands,
        );

        foreach (['cases', 'seconds', 'self-check-every', 'minimize-seconds'] as $name) {
            if ($options->integer($name, 0) < 0) {
                throw new \InvalidArgumentException("--{$name} cannot be negative");
            }
        }

        return [$server, $generator, new DifferentialExecutor($server)];
    }

    /**
     * Resolve serializer or compression choices to names both clients support.
     *
     * @param list<string> $requested
     * @param list<string> $known
     * @return list<string>
     */
    private function choices(array $requested, string $group, array $known): array
    {
        $supported = array_values(array_filter($known, static fn (string $name): bool => $name === 'none' || (
            defined('Redis::' . $group . '_' . strtoupper($name))
            && defined('Relay\\Relay::' . $group . '_' . strtoupper($name))
        )));

        if ($requested === ['random'] || $requested === ['any']) {
            return $supported;
        }

        $flag = '--' . strtolower($group);
        foreach ($requested as $name) {
            if (!in_array($name, $known, true)) {
                throw new \InvalidArgumentException("{$flag}: unknown choice {$name}");
            }
            if (!in_array($name, $supported, true)) {
                throw new \RuntimeException("{$flag}: {$name} is not supported by both PhpRedis and Relay");
            }
        }

        return array_values(array_unique($requested));
    }

    /**
     * Construct each client once with every requested option so an unusable
     * combination fails at startup, not in the middle of a case.
     *
     * @param Server $server
     * @param list<string> $serializers
     * @param list<string> $compressions
     * @param list<bool> $relayCache
     */
    private function preflight(array $server, array $serializers, array $compressions, array $relayCache): void
    {
        foreach ([DiffRuntime::PHPREDIS, DiffRuntime::RELAY] as $kind) {
            foreach ($serializers as $serializer) {
                foreach ($compressions as $compression) {
                    foreach ($relayCache as $cache) {
                        $client = DiffRuntime::client($kind, $server, ['serializer' => $serializer, 'compression' => $compression, 'relay_cache' => $cache]);
                        /** @var \Redis|\Relay\Relay $client */
                        $client->close();
                    }
                }
            }
        }
    }

    /** @param Server $server */
    private function execute(Options $options, array $server, GeneratorOptions $generatorOptions, DifferentialExecutor $executor): int
    {
        $executor->socket();
        $this->preflight($server, $generatorOptions->serializers, $generatorOptions->compressions, $generatorOptions->relayCache);

        $writer = new ReproducerWriter($options->string('output', 'reproducers/differential'));
        $minimizeSeconds = $options->integer('minimize-seconds', 60);
        $minimize = !$options->has('no-minimize') && $minimizeSeconds > 0;

        register_shutdown_function($this->saveFatal(...));

        $replay = $options->nullableString('replay');
        if ($replay !== null) {
            $case = $this->load($replay);
            $this->current = ['case' => $case, 'writer' => $writer, 'executor' => $executor, 'seed' => $case->seed, 'options' => $generatorOptions, 'save' => false];
            [$status, $report] = ReproducerWriter::report($case, $server);
            fwrite($this->output, $report);
            return $status === 0 ? ExitCode::SUCCESS : ExitCode::FAILURE;
        }

        $minimizeCase = $options->nullableString('minimize-case');
        if ($minimizeCase !== null) {
            $case = $this->load($minimizeCase);
            $this->current = ['case' => $case, 'writer' => $writer, 'executor' => $executor, 'seed' => $case->seed, 'options' => $generatorOptions, 'save' => true];
            $pair = $executor->run($case);
            if (!$pair->diverged()) {
                fwrite($this->output, "the case does not diverge; nothing to minimize\n");
                return ExitCode::SUCCESS;
            }
            $this->record($writer, $executor, $pair, 0, $generatorOptions, true, $minimizeSeconds);
            return ExitCode::FAILURE;
        }

        $seed = $options->optionalInteger('seed') ?? random_int(0, PHP_INT_MAX);
        $cases = $options->integer('cases', 100);
        $seconds = $options->integer('seconds', 0);
        $selfCheckEvery = $options->integer('self-check-every', 10);
        $worker = $options->string('worker', (string) getmypid());
        $root = $options->string('prefix-root', 'fuzz:diff');
        if ($root === '' || preg_match('/[*?\[\]\\\\]/', $root . $worker) === 1) {
            throw new \InvalidArgumentException('--prefix-root and --worker must be non-empty and free of glob characters');
        }
        $keepGoing = $options->has('keep-going');
        $verbose = $options->has('verbose');
        $failOnUnstable = $options->has('fail-on-unstable');

        $generator = new CaseGenerator($generatorOptions);
        $started = microtime(true);
        $stats = ['cases' => 0, 'operations' => 0, 'divergences' => 0, 'duplicates' => 0, 'flaky' => 0, 'unstable' => 0, 'initial_state' => 0, 'self_checks' => 0];
        $findings = [];
        $known = new SignatureIndex($writer->root() . '/signatures.txt', !$options->has('no-dedupe'));

        fwrite($this->output, sprintf(
            "redis-client-tester-diff seed=%d steps=%d keys=%d target=%s:%d php=%s redis=%s relay=%s%s\n",
            $seed,
            $generatorOptions->steps,
            $generatorOptions->keys,
            $server['host'],
            $server['port'],
            PHP_VERSION,
            phpversion('redis') ?: 'n/a',
            phpversion('relay') ?: 'n/a',
            $generatorOptions->allowFlush ? ' FLUSH ENABLED (not safe with concurrent workers)' : '',
        ));

        for ($index = 0; $cases === 0 || $index < $cases; $index++) {
            if ($seconds > 0 && microtime(true) - $started >= $seconds) {
                break;
            }

            $caseSeed = Rng::mix($seed, 'case:' . $index);
            $prefix = sprintf('%s:%s:%x:%d:', $root, $worker, $seed, $index);
            $case = $generator->generate($caseSeed, $index, $prefix);
            $this->current = ['case' => $case, 'writer' => $writer, 'executor' => $executor, 'seed' => $seed, 'options' => $generatorOptions, 'save' => true];
            $pair = $executor->run($case);
            $stats['cases']++;
            $stats['operations'] += count($case->test);

            if ($verbose) {
                fwrite($this->output, sprintf("case %d %s %s\n", $index, implode('>', $case->order), $pair->summary()));
            }

            if ($pair->comparison['initial'] !== null) {
                // Not a client finding: the neutral setup produced different
                // data twice. Report it loudly, but keep fuzzing.
                $stats['initial_state']++;
                fwrite($this->error, sprintf("case %d: initial state differed between runs: %s\n", $index, $pair->comparison['initial']));
                continue;
            }

            if ($pair->diverged()) {
                $signature = (string) $pair->signature();
                if ($known->seen($signature)) {
                    $stats['duplicates']++;
                    if ($verbose) {
                        fwrite($this->output, sprintf("duplicate case %d: %s\n", $index, $signature));
                    }
                    continue;
                }

                $confirm = $executor->run($case);
                if ($confirm->signature() !== $pair->signature()) {
                    $stats['flaky']++;
                    $dir = $this->recordUnconfirmed($writer, $known, 'flaky:' . $signature, $executor, $pair, 'flaky', 'did not repeat on confirmation: ' . $confirm->summary(), $seed, $generatorOptions);
                    fwrite($this->output, sprintf("FLAKY case %d: %s (%s)\n", $index, $pair->summary(), $dir ?? 'duplicate'));
                    continue;
                }

                $unstable = $this->selfCheckBoth($executor, $case);
                if ($unstable !== null) {
                    [$client, $self] = $unstable;
                    $stats['unstable']++;
                    $reason = "{$client} is not self-consistent: " . $self->summary();
                    $dir = $this->recordUnconfirmed($writer, $known, "unstable:{$client}:" . $self->signature(), $executor, $pair, 'unstable', $reason, $seed, $generatorOptions);
                    fwrite($this->output, sprintf("UNSTABLE case %d: %s; %s (%s)\n", $index, $pair->summary(), $reason, $dir ?? 'duplicate'));
                    if ($failOnUnstable && !$keepGoing) {
                        break;
                    }
                    continue;
                }

                $stats['divergences']++;
                $findings[] = $this->record($writer, $executor, $pair, $seed, $generatorOptions, $minimize, $minimizeSeconds);
                $known->add($signature, end($findings));
                if (!$keepGoing) {
                    break;
                }
                continue;
            }

            if ($selfCheckEvery > 0 && $index % $selfCheckEvery === $selfCheckEvery - 1) {
                $stats['self_checks']++;
                $client = $index % (2 * $selfCheckEvery) === $selfCheckEvery - 1 ? DiffRuntime::RELAY : DiffRuntime::PHPREDIS;
                $self = $executor->selfCheck($case, $client);
                if ($self !== null) {
                    $stats['unstable']++;
                    $reason = "{$client} is not self-consistent: " . $self->summary();
                    $dir = $this->recordUnconfirmed($writer, $known, "unstable:{$client}:" . $self->signature(), $executor, $self, 'unstable', $reason, $seed, $generatorOptions);
                    fwrite($this->output, sprintf("UNSTABLE case %d: %s (%s)\n", $index, $reason, $dir ?? 'duplicate'));
                    if ($failOnUnstable && !$keepGoing) {
                        break;
                    }
                }
            }
        }

        $stats['seconds'] = round(microtime(true) - $started, 3);
        $stats['seed'] = $seed;
        $stats['output'] = $writer->root();
        $stats['findings'] = count($findings) > 10 ? count($findings) : $findings;
        fwrite($this->output, 'summary ' . json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $failed = $stats['divergences'] > 0 || ($failOnUnstable && $stats['unstable'] > 0);

        return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    /**
     * Shutdown handler: when a client raised a fatal error while a case was
     * running, save the case that was executing (possibly a minimization
     * candidate) under fatal/.
     */
    private function saveFatal(): void
    {
        $error = error_get_last();
        $current = $this->current;
        if ($current === null || $error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $this->current = null;

        try {
            $case = $current['executor']->running() ?? $current['case'];
            $current['executor']->cleanup($case);
            if (!$current['save']) {
                return;
            }
            $dir = $current['writer']->allocate($case, 'fatal');
            $current['writer']->write($dir, $case, null, $current['executor']->server(), [
                'found' => date('c'),
                'classification' => 'fatal',
                'fatal' => $error['message'],
                'summary' => 'fatal error: ' . strtok($error['message'], "\n"),
                'run_seed' => $current['seed'],
                'case_seed' => $case->seed,
                'case_index' => $case->index,
                'prefix' => $case->prefix,
                'order' => $case->order,
                'client_options' => $case->options,
                'php' => PHP_VERSION,
                'phpredis' => phpversion('redis') ?: 'n/a',
                'relay' => phpversion('relay') ?: 'n/a',
                'generator' => $current['options']->toArray(),
            ]);
            fwrite($this->output, sprintf("FATAL during case %d (seed %d, prefix %s): %s\n  reproducer: %s/reproduce.php\n", $case->index, $case->seed, DiffRuntime::show($case->prefix), strtok($error['message'], "\n"), $dir));
        } catch (\Throwable $throwable) {
            fwrite($this->error, 'could not save the fatal case: ' . $throwable->getMessage() . "\n");
        }
    }

    /**
     * Run the case through each client twice. A client that cannot reproduce
     * its own behavior makes a differential verdict on this case meaningless.
     */
    /** @return array{0: string, 1: RunPair}|null The inconsistent client and its two runs. */
    private function selfCheckBoth(DifferentialExecutor $executor, TestCase $case): ?array
    {
        foreach ([DiffRuntime::PHPREDIS, DiffRuntime::RELAY] as $client) {
            $self = $executor->selfCheck($case, $client);
            if ($self !== null) {
                return [$client, $self];
            }
        }

        return null;
    }

    /** Analyse, minimize, and save a confirmed divergence; returns the reproducer path. */
    private function record(ReproducerWriter $writer, DifferentialExecutor $executor, RunPair $pair, int $seed, GeneratorOptions $generatorOptions, bool $minimize, int $minimizeSeconds): string
    {
        $case = $pair->case;
        $analysis = $executor->run($case, true);

        $minimized = null;
        $trials = 0;
        if ($minimize) {
            $minimizer = new Minimizer($executor, (float) $minimizeSeconds);
            $minimized = $minimizer->minimize($pair);
            $trials = $minimizer->trials();
        }

        $dir = $writer->allocate($case);
        $metadata = $this->metadata($executor, $pair, $analysis, $seed, $generatorOptions);
        $metadata['minimized'] = $minimized === null ? null : [
            'setup' => count($minimized->setup),
            'test' => count($minimized->test),
            'options' => $minimized->options,
            'trials' => $trials,
        ];
        $metadata['minimize_trials'] = $trials;
        $writer->write($dir, $case, $minimized, $executor->server(), $metadata);
        [$status, $report] = ReproducerWriter::report($minimized ?? $case, $executor->server());
        $writer->writeReport($dir, $report);

        fwrite($this->output, sprintf(
            "DIVERGENCE case %d (seed %d, prefix %s, order %s): %s\n  reproducer: %s/reproduce.php%s%s\n",
            $case->index,
            $case->seed,
            DiffRuntime::show($case->prefix),
            implode('>', $case->order),
            $analysis->summary(),
            $dir,
            $minimized === null ? '' : sprintf(' (minimized %d->%d ops, %d->%d setup)', count($case->test), count($minimized->test), count($case->setup), count($minimized->setup)),
            $status === 1 ? '' : ' [warning: the saved reproducer did not reproduce when re-run]',
        ));

        return $dir . '/reproduce.php';
    }

    /**
     * Save a divergence that failed confirmation or self-consistency, without
     * minimizing it. Returns null when its signature was already saved.
     */
    private function recordUnconfirmed(ReproducerWriter $writer, SignatureIndex $known, string $signature, DifferentialExecutor $executor, RunPair $pair, string $category, string $reason, int $seed, GeneratorOptions $generatorOptions): ?string
    {
        if ($known->seen($signature)) {
            return null;
        }
        $dir = $writer->allocate($pair->case, $category);
        $known->add($signature, $dir);
        $metadata = $this->metadata($executor, $pair, null, $seed, $generatorOptions);
        $metadata['classification'] = $category;
        $metadata['reason'] = $reason;
        $writer->write($dir, $pair->case, null, $executor->server(), $metadata);

        return $dir;
    }

    /** @return array<string, mixed> */
    private function metadata(DifferentialExecutor $executor, RunPair $pair, ?RunPair $analysis, int $seed, GeneratorOptions $generatorOptions): array
    {
        $case = $pair->case;
        $server = $executor->server();
        $relayIni = [];
        foreach ((ini_get_all('relay', false) ?: []) as $name => $value) {
            $relayIni[(string) $name] = is_scalar($value) ? (string) $value : null;
        }
        $facts = $executor->serverFacts();
        $version = $facts['valkey_version'] ?? $facts['redis_version'] ?? 'unknown';

        return [
            'found' => date('c'),
            'classification' => 'divergence',
            'signature' => $pair->signature(),
            'summary' => $pair->summary(),
            'analysis' => $analysis === null ? null : [
                'summary' => $analysis->summary(),
                'signature' => $analysis->signature(),
                'first_reply_divergence' => $analysis->comparison['reply'],
                'first_state_divergence' => $analysis->comparison['state'],
            ],
            'run_seed' => $seed,
            'case_seed' => $case->seed,
            'case_index' => $case->index,
            'prefix' => $case->prefix,
            'order' => $case->order,
            'client_options' => $case->options,
            'resp_version' => 2,
            'relay_phpredis_compatibility' => true,
            'php' => PHP_VERSION . (PHP_DEBUG ? ' (debug)' : '') . (PHP_ZTS ? ' (zts)' : ''),
            'php_binary' => PHP_BINARY,
            'phpredis' => phpversion('redis') ?: 'n/a',
            'relay' => phpversion('relay') ?: 'n/a',
            'relay_ini' => $relayIni,
            'server' => sprintf('%s:%d (%s)', $server['host'], $server['port'], is_scalar($version) ? (string) $version : 'unknown'),
            'server_facts' => $facts,
            'ttl_tolerance_ms' => $server['ttl_tolerance_ms'],
            'strict_messages' => $server['strict_messages'],
            'generator' => $generatorOptions->toArray(),
            'rerun' => sprintf('redis-client-tester-diff --seed=%d --steps=%d --cases=%d ...', $seed, $generatorOptions->steps, $case->index + 1),
        ];
    }

    private function load(string $path): TestCase
    {
        $json = @file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException("cannot read {$path}");
        }

        return TestCase::fromJson($json);
    }

    private const HELP = <<<'HELP'
redis-client-tester-diff - differential stateful fuzzer: find behavior differences
between PhpRedis and Relay

Every case is generated from a seed as explicit data: raw setup commands and a
sequence of client operations. The case runs as
  reset -> seed -> client A -> record, reset -> seed -> client B -> record
using a separate raw RESP connection for every reset, seed, and state
inspection, then compares replies (with Redis-semantics-aware
canonicalization) and the resulting Redis state. A confirmed divergence is
saved as case.json, metadata.json, and a standalone reproduce.php.

Usage:
  redis-client-tester-diff [options]

Connection:
  --tls                     Encrypt client and neutral RESP connections
  --tls-options=JSON        PHP SSL context options; implies --tls
  --host=HOST                 Redis host (default: 127.0.0.1)
  --port=PORT                 Redis port (default: 6379)
  --username=USER             ACL username (with --password)
  --password=PASS             Password
  --timeout=SECONDS           Connect timeout (default: 2)
  --read-timeout=SECONDS      Read timeout (default: 5)

Run:
  --seed=N                    Run seed; case i uses a seed derived from it
                              (generated and printed when omitted)
  --cases=N                   Cases to run; 0 is unlimited (default: 100)
  --seconds=N                 Stop after N seconds; 0 disables (default: 0)
  --steps=N                   Test operations per case (default: 20). A smaller
                              value yields a prefix of the same sequence, so
                              redis-client-tester-harness --reduce steps works
  --keep-going                Keep fuzzing after a divergence (default: stop)
  --self-check-every=N        Every Nth passing case, run it twice through one
                              client to check self-consistency; 0 disables
                              (default: 10)
  --fail-on-unstable          Exit 1 when a client is not self-consistent
  --verbose                   Print one line per case
  --output=DIR                Where findings are saved
                              (default: reproducers/differential)
  --no-dedupe                 Save every divergence. By default a divergence
                              whose signature (kind, operation, and what
                              differed) is already listed in DIR/signatures.txt,
                              by this or any earlier or concurrent run, is
                              counted as a duplicate and neither saved nor
                              treated as a failure. Use --no-dedupe together
                              with redis-client-tester-harness --reduce steps, whose
                              re-runs would otherwise be skipped as duplicates
  --no-minimize               Save the unminimized case only
  --minimize-seconds=N        Time budget for minimizing one divergence
                              (default: 60)
  --replay=FILE               Run a saved case.json and print its report
  --minimize-case=FILE        Minimize a saved case.json and save the result

Generation:
  --keys=N                    Keys per case (default: 6)
  --members=N                 Maximum setup collection size (default: 8)
  --commands=LIST             Operation names and groups, e.g. @hash,get,-hGetAll
                              Groups: @string @generic @hash @list @set @zset
                              @txn (MULTI/PIPELINE blocks) @all; see
                              --list-commands (default: everything but flushes)
  --wrongtype-chance=P        Aim an operation at a key of another type (0.08)
  --missing-chance=P          Aim an operation at a missing key (0.08)
  --edge-chance=P             Boundary/binary/malformed argument rate (0.15)
  --txn-chance=P              Start a MULTI or PIPELINE block (0.05)
  --ttl-chance=P              Give a setup key a TTL (0.15)
  --max-value-bytes=N         Largest generated value (default: 1024)
  --serializer=LIST           Per-case choice: none, php, igbinary, msgpack,
                              json, or random (default: none)
  --compression=LIST          Per-case choice: none, lzf, zstd, lz4, or random
                              (default: none)
  --relay-cache=MODE          Relay OPT_USE_CACHE: on, off, random (default: on)
  --order=MODE                random, phpredis-first, relay-first (default: random)
  --ttl-tolerance-ms=N        TTL difference treated as timing noise (2000)
  --strict-messages           Compare exception and warning text exactly. By
                              default exceptions compare by class and warnings
                              by count, since the clients word their own
                              diagnostics differently; Redis error replies
                              (getLastError) always compare exactly
  --worker=ID                 Worker id in the key prefix (default: the pid)
  --prefix-root=STR           Key prefix root (default: fuzz:diff)
  --allow-flush               Also generate FLUSHDB/FLUSHALL. These wipe every
                              worker's keys: never use with concurrent runs
  --list-commands             List the operation catalog and exit

Every key a case touches lives under <prefix-root>:<worker>:<seed>:<case>:,
and cleanup only SCANs and UNLINKs that prefix, so many workers can share one
Redis. Exit status: 0 no divergence, 1 divergence or failure (including an
unreachable target), 78 rejected command line or missing extension.

Examples:
  redis-client-tester-diff --port 6379 --cases 500 --verbose
  redis-client-tester-harness --jobs 8 --capture failures --reduce steps \
      --port 6379 -- bin/redis-client-tester-diff --port={port} --steps={steps} \
      --no-minimize --cases 200
  php reproducers/differential/1a2b3c4d/reproduce.php

Only ever point this at a disposable Redis target.

HELP;
}
