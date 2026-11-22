<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Cli;

use Mgrunder\RedisClientTester\ClientType;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\Registry;
use Mgrunder\RedisClientTester\Compatibility\Process;
use Mgrunder\RedisClientTester\Compatibility\Minimizer;
use Mgrunder\RedisClientTester\Compatibility\Recording;
use Mgrunder\RedisClientTester\Compatibility\Target;
use Mgrunder\RedisClientTester\Differential\DiffRuntime;
use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Harness\HarnessOptions;
use Mgrunder\RedisClientTester\Harness\PhpStartupTemplate;
use Mgrunder\RedisClientTester\InvocationRecorder;

/** @phpstan-import-type Tape from Recording
 * @phpstan-import-type Outcome from DiffRuntime
 * @phpstan-import-type Snapshot from DiffRuntime
 * @phpstan-type Result array{outcomes: list<Outcome>, state: Snapshot}
 * @phpstan-type Difference array{signature: string, detail: string, index: ?int}
 * @phpstan-type Worker array{action: string, tape: string, result: string, arguments: list<string>, type: string, port: int, password: string}
 */
final class CompatibilityApplication
{
    private const VALUES = ['php', 'php2', 'php-args', 'php-args2', 'php-ini', 'php-ini2',
        'client2', 'redis-server', 'artifacts', 'replay', 'minimize-seconds', 'worker-timeout'];
    private const FLAGS = ['no-minimize'];
    private int $trial = 0;
    private bool $interrupted = false;

    /**
     * @internal
     * @param array<string, mixed> $values
     * @return list<string>
     */
    public static function configurationArguments(array $values): array
    {
        return YamlConfiguration::options(
            $values, self::VALUES, self::FLAGS, ['php-args', 'php-args2'],
        );
    }

    /**
     * @param list<string> $arguments */
    public function run(array $arguments): int
    {
        if (count($arguments) === 2 && $arguments[0] === '--internal-worker') {
            return $this->worker($arguments[1]);
        }
        try {
            $arguments = YamlConfiguration::expand($arguments, 'compatibility');
            if (in_array('--help', $arguments, true)) {
                fwrite(STDOUT, self::HELP);
                return 0;
            }
            [$own, $workload] = self::split($arguments);
            $options = Options::parse($own, self::VALUES, self::FLAGS, ['php-args', 'php-args2']);
            $run = Application::parseOptions($workload);
            $first = ClientType::from($run->string('client', 'redis'));
            $second = ClientType::from($options->string('client2', $first->value));
            if ($first->isCluster() !== $second->isCluster()) {
                throw new \InvalidArgumentException('Both clients must use the same topology');
            }
            foreach (['host', 'port', 'seeds', 'username', 'password', 'tls', 'tls-options', 'persistent',
                'forks', 'seconds', 'catch', 'pick-client', 'differential', 'hook', 'hook-arg', 'scenarios',
                'saturate-chance', 'saturate-target', 'raw-chaos', 'allow-deserialization-mutations',
                'deserialization-failure-chance'] as $name) {
                if ($run->has($name)) {
                    throw new \InvalidArgumentException("--{$name} is not supported by isolated compatibility replay");
                }
            }
            $timeout = $options->number('worker-timeout', 60);
            $budget = $options->number('minimize-seconds', 30);
            if (!is_finite($timeout) || $timeout <= 0 || !is_finite($budget) || $budget < 0
                || $run->integer('steps', 100) <= 0) {
                throw new \InvalidArgumentException('Steps and worker timeout must be positive; minimization budget must be non-negative');
            }
            $seed = $run->optionalInteger('seed') ?? random_int(0, 0x7fffffff);
            $base = $options->string('artifacts', (getenv('REDIS_CLIENT_TESTER_WORK_DIR') ?: 'reproducers') . '/compatibility');
            Fs::ensureDir($base);
            $dir = $base . '/' . getmypid() . '-' . bin2hex(random_bytes(4));
            if (!mkdir($dir, 0o700)) {
                throw new \RuntimeException('Cannot allocate compatibility artifacts');
            }
            $dir = realpath($dir) ?: $dir;
            $php = $options->string('php', PHP_BINARY);
            $php2 = $options->string('php2', $php);
            $startup = [];
            foreach (['', '2'] as $suffix) {
                $side = $dir . '/php' . ($suffix ?: '1');
                Fs::ensureDir($side);
                $args = HarnessOptions::argvList($options->repeated('php-args' . $suffix, splitCommas: false));
                $ini = $options->nullableString('php-ini' . $suffix);
                if ($ini !== null && array_filter($args, static fn (string $arg): bool => str_starts_with($arg, '-c')) !== []) {
                    throw new \InvalidArgumentException('Use --php-ini or -c in --php-args, not both');
                }
                $rendered = (new PhpStartupTemplate($args, $ini))->render($seed, $side);
                if ($ini !== null && $rendered['ini'] !== $side . '/php.ini') {
                    if (!copy($ini, $side . '/php.ini')) {
                        throw new \RuntimeException('Cannot preserve PHP ini');
                    }
                    $rendered['args'][1] = $side . '/php.ini';
                }
                $startup[] = $rendered['args'];
            }
            $methods = [];
            foreach ([$first, $second] as $index => $type) {
                $class = var_export($type->className(), true);
                $check = 'if (PHP_VERSION_ID < 80400 || !extension_loaded("redis") || !class_exists(' . $class
                    . ')) { exit(78); } echo "compatibility-ready\n", json_encode(array_map("strtolower", get_class_methods(' . $class . ')));';
                $log = $dir . '/preflight-' . $index;
                $status = Process::run([$index === 0 ? $php : $php2, ...$startup[$index], '-r', $check], $log, $timeout);
                $out = (string) file_get_contents($log . '.stdout.log');
                $err = file_get_contents($log . '.stderr.log');
                [$ready, $list] = explode("\n", $out, 2) + ['', ''];
                $list = json_decode($list, true);
                if ($status !== 0 || $ready !== 'compatibility-ready' || $err !== '' || !is_array($list)) {
                    throw new \RuntimeException('PHP side ' . ($index + 1) . ' preflight failed: '
                        . ($err ?: $out ?: 'requires PHP >=8.4, PhpRedis and ' . $type->className()));
                }
                $methods[] = $list;
            }
        } catch (\Throwable $error) {
            fwrite(STDERR, 'compatibility: ' . $error->getMessage() . "\n");
            return ExitCode::STARTUP;
        }

        $target = null;
        try {
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
                foreach ([SIGTERM, SIGINT] as $signal) {
                    pcntl_signal($signal, function (int $signal): never {
                        $this->interrupted = true;
                        throw new \RuntimeException('Compatibility interrupted', 128 + $signal);
                    });
                }
            }
            $target = new Target($dir . '/redis', $first->isCluster(), $options->string('redis-server', 'redis-server'));
            $script = dirname(__DIR__, 2) . '/bin/compatibility';
            $commands = [[$php, ...$startup[0], $script], [$php2, ...$startup[1], $script]];
            $tapePath = $dir . '/original.case';
            $replay = $options->nullableString('replay');
            $unsupported = [];
            if ($replay === null) {
                $excluded = [];
                foreach (new Registry() as $command) {
                    // Generation only calls methods the first client has; a method the second lacks
                    // would fail every run with "undefined method" and mask real differences.
                    $missing = $command instanceof FuzzInterface && in_array($command->name(), $methods[0], true)
                        && !in_array($command->name(), $methods[1], true);
                    if ($missing) {
                        $unsupported[] = $command->name();
                    }
                    if ($missing || !$command->supportsCompatibilityReplay()) {
                        $excluded[] = '-' . $command->name();
                    }
                }
                $workload = [...$workload, '--seed=' . $seed, '--host=127.0.0.1', '--port=' . $target->ports[0],
                    '--password=' . $target->password, '--commands=' . implode(',', $excluded),
                    '--script-log=' . $dir . '/generate.php'];
                $spec = ['action' => 'generate', 'tape' => $tapePath, 'result' => $dir . '/generation.bin',
                    'arguments' => $workload, 'type' => $first->value, 'port' => $target->ports[0], 'password' => $target->password];
                $this->launch($commands[0], $spec, $dir . '/generate', $timeout);
            } else {
                Recording::write($tapePath, Recording::read($replay));
            }
            $tape = Recording::read($tapePath);
            if ($tape['configuration']->type->isCluster() !== $first->isCluster()) {
                throw new \InvalidArgumentException('Replay topology does not match the selected clients');
            }
            $reproduce = [$script, '--php=' . $php, '--php2=' . $php2,
                '--client=' . $first->value, '--client2=' . $second->value,
                '--redis-server=' . $options->string('redis-server', 'redis-server'), '--seed=' . $seed,
                '--worker-timeout=' . $timeout, '--no-minimize'];
            foreach ($startup as $index => $args) {
                foreach ($args as $arg) {
                    $reproduce[] = '--php-args' . ($index === 0 ? '' : '2') . '=' . escapeshellarg($arg);
                }
            }
            // Written before any replay so a worker crash or timeout still leaves a runnable reproducer.
            Recording::write($dir . '/minimized.case', $tape);
            self::writeReproducer($dir, [PHP_BINARY, ...$reproduce]);
            $original = $this->pair($tape, $target, $commands, [$first, $second], $dir, $timeout);
            $difference = self::difference($tape, $original[0], $original[1]);
            $report = ['seed' => $seed, 'clients' => [$first->value, $second->value],
                'calls' => count($tape['calls']), 'difference' => $difference, 'stable' => null,
                'excluded_missing_on_client2' => $unsupported];
            $code = 0;
            $best = $tape;
            if ($difference !== null) {
                $again = $this->pair($tape, $target, $commands, [$first, $second], $dir, $timeout);
                $stable = self::difference($tape, $original[0], $again[0]) === null
                    && self::difference($tape, $original[1], $again[1]) === null;
                $report['stable'] = $stable;
                $code = $stable ? ExitCode::COMPATIBILITY : ExitCode::FAILURE;
                if ($stable && !$options->has('no-minimize')) {
                    $best = Minimizer::reduce($tape,
                        /** @param Tape $candidate */
                        function (array $candidate) use ($target, $commands, $first, $second, $dir, $timeout, $difference): bool {
                            try {
                                $trial = $this->pair($candidate, $target, $commands, [$first, $second], $dir, $timeout);
                                $found = self::difference($candidate, $trial[0], $trial[1]);
                            } catch (\Throwable $error) {
                                // A candidate that crashes or hangs a worker is a different failure; keep the
                                // stable divergence instead of aborting the whole comparison.
                                if ($this->interrupted) {
                                    throw $error;
                                }
                                return false;
                            }
                            return $found !== null && $found['signature'] === $difference['signature'];
                        }, $budget);
                    // A reduced sequence can introduce instability even when the original was stable.
                    $check = $this->pair($best, $target, $commands, [$first, $second], $dir, $timeout);
                    $confirm = $this->pair($best, $target, $commands, [$first, $second], $dir, $timeout);
                    $found = self::difference($best, $check[0], $check[1]);
                    if ($found === null || $found['signature'] !== $difference['signature']
                        || self::difference($best, $check[0], $confirm[0]) !== null
                        || self::difference($best, $check[1], $confirm[1]) !== null) {
                        $best = $tape;
                    }
                }
            }
            Recording::write($dir . '/minimized.case', $best);
            $report['minimized_calls'] = count($best['calls']);
            Fs::write($dir . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            fwrite(STDOUT, sprintf("compatibility: %s; %d calls (%d minimized); seed %d; artifacts %s\n",
                $difference === null ? 'matched' : ($report['stable'] ? 'DIVERGENCE' : 'unstable replay'),
                count($tape['calls']), count($best['calls']), $seed, $dir));
            if ($difference !== null) {
                fwrite(STDOUT, $difference['detail'] . "\n");
            }
            return $code;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'compatibility: ' . $error->getMessage() . '; artifacts ' . $dir . "\n");
            return $error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : ExitCode::FAILURE;
        } finally {
            $target?->stop();
        }
    }

    /** @internal
     * @param list<string> $command
     */
    public static function writeReproducer(string $dir, array $command): void
    {
        $source = "<?php\n\$command = " . var_export($command, true) . ";\n"
            . '\$command = array_map(static fn (string \$arg): string => str_replace('
            . var_export($dir, true) . ', __DIR__, \$arg), \$command);' . "\n"
            . '\$process = proc_open([...\$command, "--replay=" . __DIR__ . "/minimized.case", ...array_slice(\$argv, 1)], [STDIN, STDOUT, STDERR], \$pipes);' . "\n"
            . 'exit(is_resource(\$process) ? proc_close(\$process) : 1);' . "\n";
        Fs::write($dir . '/reproduce.php', str_replace('\\$', '$', $source));
        Fs::write($dir . '/reproduce.sh', "#!/usr/bin/env bash\nset -euo pipefail\n"
            . 'COMPAT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)' . "\n"
            . 'exec ' . escapeshellarg(PHP_BINARY) . ' "$COMPAT_DIR/reproduce.php" "$@"' . "\n");
    }

    /**
     * @param Tape $tape
     * @param array{list<string>, list<string>} $commands
     * @param array{ClientType, ClientType} $types
     * @return array{Result, Result}
     */
    private function pair(array $tape, Target $target, array $commands, array $types, string $dir, float $timeout): array
    {
        $path = $dir . '/trial-' . ++$this->trial;
        Recording::write($path . '.case', $tape);
        $results = [];
        foreach ($commands as $index => $command) {
            $target->reset();
            $result = $path . '-' . $index . '.bin';
            $spec = ['action' => 'replay', 'tape' => $path . '.case', 'result' => $result, 'arguments' => [],
                'type' => $types[$index]->value, 'port' => $target->ports[0], 'password' => $target->password];
            $this->launch($command, $spec, $path . '-' . $index, $timeout);
            $contents = file_get_contents($result);
            if ($contents === false) {
                throw new \RuntimeException('Worker did not produce a result');
            }
            /** @var list<Outcome> $outcomes */
            $outcomes = unserialize($contents);
            $state = $target->snapshot();
            $results[] = ['outcomes' => $outcomes, 'state' => $state];
            Recording::write($path . '-' . $index . '.state', $state);
        }
        return [$results[0], $results[1]];
    }

    /**
     * @param Tape $tape
     * @param Result $a
     * @param Result $b
     * @return Difference|null */
    public static function difference(array $tape, array $a, array $b): ?array
    {
        if (count($a['outcomes']) !== count($tape['calls']) || count($b['outcomes']) !== count($tape['calls'])) {
            throw new \UnexpectedValueException('Incomplete worker results');
        }
        foreach ($tape['calls'] as $index => $call) {
            foreach (['value', 'exception', 'warnings', 'error'] as $field) {
                if (($a['outcomes'][$index][$field] ?? null) !== ($b['outcomes'][$index][$field] ?? null)) {
                    return ['signature' => $call['command'] . ':' . $field, 'index' => $index,
                        'detail' => 'call ' . $index . ' ' . $call['command'] . ' ' . $field . " differs:\n"
                            . DiffRuntime::show($a['outcomes'][$index][$field]) . "\nvs\n"
                            . DiffRuntime::show($b['outcomes'][$index][$field])];
                }
            }
        }
        $state = DiffRuntime::stateDiff($a['state'], $b['state'], 0);
        return $state === null ? null : ['signature' => 'state:' . strtok($state, "\n"), 'index' => null, 'detail' => $state];
    }

    /**
     * @param list<string> $command
     * @param Worker $spec */
    private function launch(array $command, array $spec, string $path, float $timeout): void
    {
        Recording::write($path . '.worker', $spec);
        $exit = Process::run([...$command, '--internal-worker', $path . '.worker'], $path, $timeout);
        if ($exit !== 0) {
            throw new \RuntimeException("Worker exited {$exit}; see {$path}.stderr.log", $exit);
        }
    }

    private function worker(string $path): int
    {
        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Cannot read worker specification');
            }
            /** @var Worker $spec */
            $spec = unserialize($contents);
            Fs::write($path . '.versions.json', json_encode(['php' => PHP_VERSION, 'binary' => PHP_BINARY,
                'redis' => phpversion('redis'), 'relay' => phpversion('relay')], JSON_THROW_ON_ERROR));
            if ($spec['action'] === 'generate') {
                $recording = new Recording($spec['tape']);
                InvocationRecorder::$observer = $recording->record(...);
                try {
                    $exit = (new Application())->run($spec['arguments']);
                } finally {
                    InvocationRecorder::$observer = null;
                }
                if ($exit !== 0) {
                    return $exit;
                }
                $recording->save();
            } else {
                Recording::write($spec['result'], Recording::replay(Recording::read($spec['tape']),
                    ClientType::from($spec['type']), $spec['port'], $spec['password']));
            }
            return 0;
        } catch (\Throwable $error) {
            fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
            return ExitCode::STARTUP;
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{list<string>, list<string>} */
    public static function split(array $arguments): array
    {
        $own = [];
        $workload = [];
        for ($index = 0; $index < count($arguments); $index++) {
            $token = $arguments[$index];
            $name = explode('=', substr($token, 2), 2)[0];
            if (in_array($name, [...self::VALUES, ...self::FLAGS], true)) {
                $own[] = $token;
                if (in_array($name, self::VALUES, true) && !str_contains($token, '=') && isset($arguments[$index + 1])) {
                    $own[] = $arguments[++$index];
                }
            } else {
                $workload[] = $token;
            }
        }
        return [$own, $workload];
    }

    private const HELP = <<<'HELP'
Usage: compatibility [comparison options] [tester workload options]

Runs concrete identical client calls in separate PHP processes, each starting
from empty private Redis servers. No existing Redis endpoint is accepted.
Standalone and three-primary cluster comparisons support all four clients.

  --config FILE               Load YAML compatibility and workload settings
  --php PATH / --php2 PATH        PHP executables (default: current PHP / first PHP)
  --php-args ARGS / --php-args2 ARGS  Repeatable shell-style startup arguments
  --php-ini FILE / --php-ini2 FILE   Independent ini files (supports harness choices)
  --client TYPE / --client2 TYPE  redis, relay, redis-cluster, relay-cluster
  --redis-server PATH            Redis 7+ executable (default: redis-server)
  --artifacts DIR                Artifact root (default: reproducers/compatibility)
  --replay FILE                  Replay a TRUSTED original.case or minimized.case
  --minimize-seconds N           Removal minimization budget (default: 30)
  --no-minimize                  Skip minimization
  --worker-timeout N             Deadline per child in seconds (default: 60)

Workload options: bin/tester --help. Steps, seed, keys, members, serializers,
compressors, prefixes, weights, wrong-type/cross-slot chances, raw and invocation
mode are reused. One client per side. No forks, hooks, stateful scenarios,
wall-clock limits, external endpoints, saturation or raw-chaos. Commands requiring
random choices, expiration, scan cursors, server metadata or stream IDs are excluded.
Flush commands remain opt-in (--include=flush) and affect only private servers.

Replies, warnings, exceptions, Redis errors and final logical data are compared.
A same-client replay checks stability before minimization and capture.
Artifacts contain exact arguments, both results, versions, logs, original and
minimized cases, and reproduce.sh. Case files are trusted executable PHP data.
Exit: 0 matched, 2 stable compatibility difference, 1 unstable/error,
78 invalid startup, 124 timeout, 128+signal worker crash.

Example:
  bin/compatibility --php-args='-n -d extension=/path/redis-6.3.so' \
      --php-args2='-n -d extension=/path/redis-6.4.so' --seed=42 --steps=1000
  bin/harness --capture=compatibility --jobs=4 --steps=1000 \
      --php-args='-n -d extension=/path/redis-6.3.so' \
      --php-args2='-n -d extension=/path/redis-6.4.so' \
      -- bin/compatibility --steps={steps}

HELP;
}
