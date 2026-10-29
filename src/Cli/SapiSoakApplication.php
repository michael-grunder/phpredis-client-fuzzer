<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Cli;

use Mgrunder\RedisClientTester\Harness\PhpStartupTemplate;
use Mgrunder\RedisClientTester\Harness\ReproStore;
use Mgrunder\RedisClientTester\Sapi\Request;
use Mgrunder\RedisClientTester\Sapi\RequestScript;
use Mgrunder\RedisClientTester\Sapi\Server;
use Mgrunder\RedisClientTester\Sapi\SoakMonitor;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Independent long-lived server and continuous, locally generated HTTP workloads. */
final class SapiSoakApplication
{
    public function __construct(private readonly string $autoload)
    {
    }

    /**
     * @param list<string> $arguments
     * @param string|null $preparedDirectory Internal daemon handoff of a freshly claimed directory.
     */
    public function run(array $arguments, ?string $preparedDirectory = null): int
    {
        $directory = null;
        $started = false;
        $detached = false;
        try {
            $mode = array_shift($arguments);
            if ($mode === 'client') {
                return $this->client($arguments);
            }
            if ($mode !== 'serve') {
                throw new \InvalidArgumentException('expected serve or client');
            }
            $options = Options::parse($arguments,
                ['workers', 'directory', 'php-arg', 'php-ini', 'server-prefix', 'request-timeout', 'history', 'seed'],
                ['help', 'daemon', 'no-tui'], ['php-arg', 'server-prefix']);
            if ($options->has('help')) {
                echo self::HELP;
                return 0;
            }
            Server::assertSupported();
            $workers = $options->integer('workers', 4);
            $history = $options->integer('history', 10);
            $timeout = $options->number('request-timeout', 30);
            if ($workers < 2 || $workers > 128 || $history < 1 || $history > 1000
                || !is_finite($timeout) || $timeout <= 0) {
                throw new \InvalidArgumentException('require workers 2..128, history 1..1000 and positive finite request-timeout');
            }
            $seed = $options->integer('seed', 0);
            $startup = new PhpStartupTemplate($options->repeated('php-arg', splitCommas: false), $options->nullableString('php-ini'));
            if ($preparedDirectory === null) {
                $requestedDirectory = $options->string('directory', sys_get_temp_dir() . '/redis-client-tester-soak-' . bin2hex(random_bytes(8)));
                if (!@mkdir($requestedDirectory, 0o700, true)) {
                    throw new \RuntimeException("cannot create fresh run directory: {$requestedDirectory}");
                }
                $directory = realpath($requestedDirectory) ?: throw new \RuntimeException('cannot resolve run directory');
            } else {
                $directory = $preparedDirectory;
            }
            if ($options->has('daemon')) {
                $detached = true;
                return $this->daemon($directory, $arguments, $timeout);
            }
            if (!mkdir($directory . '/clients', 0o700)) {
                throw new \RuntimeException('cannot create client directory');
            }
            Server::write($directory . '/supervisor.pid', getmypid() . "\n");
            $phpArgs = $startup->render($seed, $directory)['args'];
            $tui = !$options->has('no-tui') && stream_isatty(STDOUT);
            $monitor = new SoakMonitor($directory, $history, $tui);
            $connection = $this->clientCommand($directory);
            $published = false;
            $started = true;
            try {
                $code = (new Server($directory, $workers, 1, 0, $timeout, !$tui))->run(
                    [], $phpArgs, $options->repeated('server-prefix', splitCommas: false),
                    function (string $address, string $token) use ($directory, $workers, $phpArgs, $seed, $monitor, $connection, &$published): bool {
                        if (!$published) {
                            $metadata = json_encode([
                                'address' => $address, 'token' => $token, 'pid' => getmypid(), 'workers' => $workers,
                                'php' => PHP_BINARY, 'php_args' => $phpArgs, 'seed' => $seed, 'started' => date(DATE_ATOM),
                            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                            Server::write($directory . '/connection.json.tmp', $metadata);
                            if (!rename($directory . '/connection.json.tmp', $directory . '/connection.json')) {
                                throw new \RuntimeException('cannot publish server connection');
                            }
                            echo "SAPI artifacts: {$directory}\nClient: {$connection}\nStop: kill -TERM " . getmypid() . "\n";
                            $published = true;
                        }
                        $monitor->render($connection);
                        return true;
                    },
                );
            } finally {
                try {
                    $monitor->render($connection, true);
                } finally {
                    $monitor->stop();
                }
            }
            Server::write($directory . '/exit.json', json_encode(['exit' => $code, 'stopped' => date(DATE_ATOM)], JSON_THROW_ON_ERROR) . "\n");
            return $code;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'redis-client-tester-sapi: ' . $error->getMessage() . "\n");
            $code = $started ? ExitCode::FAILURE : ExitCode::STARTUP;
            if (!$detached && $directory !== null && !is_file($directory . '/exit.json')) {
                Server::write($directory . '/exit.json', json_encode(['exit' => $code, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n");
            }
            return $code;
        }
    }

    /** @param list<string> $arguments */
    private function daemon(string $directory, array $arguments, float $timeout): int
    {
        // Re-exec with file descriptors redirected before creating the server process group.
        $source = 'require $argv[1]; exit((new \\Mgrunder\\RedisClientTester\\Cli\\SapiSoakApplication($argv[1]))->run(array_slice($argv, 3), $argv[2]));';
        $args = array_values(array_filter($arguments, static fn (string $arg): bool => $arg !== '--daemon'));
        $process = proc_open(['/usr/bin/setsid', '--fork', PHP_BINARY, '-r', $source,
            $this->autoload, $directory, 'serve', ...$args, '--no-tui'], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/supervisor.log', 'a'],
                2 => ['file', $directory . '/supervisor.log', 'a'],
            ], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException('cannot detach server supervisor');
        }
        $deadline = microtime(true) + $timeout + 5;
        do {
            clearstatcache();
            if (is_file($directory . '/exit.json')) {
                throw new \RuntimeException("daemon startup failed; see {$directory}/supervisor.log");
            }
            if (is_file($directory . '/connection.json')) {
                $pid = trim((string) file_get_contents($directory . '/supervisor.pid'));
                echo "SAPI daemon PID: {$pid}\nArtifacts and log: {$directory}\nClient: " . $this->clientCommand($directory) . "\nStop: kill -TERM {$pid}\n";
                return 0;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        $pid = @file_get_contents($directory . '/supervisor.pid');
        if ($pid !== false && ctype_digit(trim($pid))) {
            posix_kill((int) $pid, SIGTERM);
        }
        throw new \RuntimeException("daemon readiness timed out; see {$directory}/supervisor.log");
    }

    private function clientCommand(string $directory): string
    {
        return ReproStore::formatCommand([PHP_BINARY, dirname(__DIR__, 2) . '/bin/redis-client-tester-sapi',
            'client', '--directory=' . $directory, '--']) . ' [workload options]';
    }

    /** @param list<string> $arguments */
    private function client(array $arguments): int
    {
        $separator = array_search('--', $arguments, true);
        $options = Options::parse($separator === false ? $arguments : array_slice($arguments, 0, $separator),
            ['directory', 'requests', 'idle-ms', 'request-timeout'], ['help']);
        if ($options->has('help')) {
            echo self::HELP;
            return 0;
        }
        if ($separator === false) {
            throw new \InvalidArgumentException('separate client options from workload options with --');
        }
        Server::assertSupported();
        $workload = array_slice($arguments, $separator + 1);
        $fuzzer = Application::parseOptions($workload);
        foreach (['forks', 'script-log', 'help'] as $name) {
            if ($fuzzer->has($name)) {
                throw new \InvalidArgumentException("--{$name} is managed by the SAPI client or unavailable in HTTP requests");
            }
        }
        $requests = $options->integer('requests', 0);
        $idle = $options->integer('idle-ms', 100);
        $timeout = $options->number('request-timeout', 30);
        $seed = $fuzzer->optionalInteger('seed') ?? random_int(0, mt_getrandmax());
        if ($requests < 0 || $idle < 0 || $idle > 60000 || $seed < 0 || $seed > mt_getrandmax()
            || !is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('require requests >= 0, idle-ms 0..60000, seed 0..2147483647 and positive finite request-timeout');
        }
        $directory = realpath($options->string('directory', ''));
        if ($directory === false || !is_dir($directory . '/clients') || is_file($directory . '/exit.json')) {
            throw new \InvalidArgumentException('--directory must name a running SAPI server directory');
        }
        $json = @file_get_contents($directory . '/connection.json');
        $connection = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($connection) || !is_string($connection['address'] ?? null) || !is_string($connection['token'] ?? null)) {
            throw new \InvalidArgumentException('missing or invalid server connection.json');
        }
        $http = new Request($connection['address'], $connection['token'], $timeout);
        $client = 'client-' . bin2hex(random_bytes(8));
        $clientDirectory = $directory . '/clients/' . $client;
        if (!mkdir($clientDirectory, 0o700)) {
            throw new \RuntimeException('cannot claim client directory');
        }
        Server::write($clientDirectory . '/workload.json', json_encode([
            'seed' => $seed, 'arguments' => $workload, 'requests' => $requests, 'idle_ms' => $idle,
            'request_timeout' => $timeout, 'started' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        fwrite(STDERR, "SAPI client artifacts: {$clientDirectory}\nSeed: {$seed}\n");
        $stopped = 0;
        $previousAsync = pcntl_async_signals(true);
        $handlers = [];
        foreach ([SIGINT, SIGTERM] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $signal) use (&$stopped): void { $stopped = $signal; });
        }
        $signal = static function () use (&$stopped): int { return $stopped; };
        $code = 0;
        try {
            $code = $http->run('ready', $clientDirectory . '/ready.http', $signal, false);
            if ($code !== 0) {
                return $code;
            }
            $random = new Randomizer(new Mt19937($seed));
            for ($index = 0; $requests === 0 || $index < $requests; $index++) {
                if ($stopped !== 0) {
                    return $code = 128 + $stopped;
                }
                $requestSeed = $index === 0 ? $seed : $random->getInt(0, mt_getrandmax());
                $bucket = (string) intdiv($index, 1000);
                $target = $clientDirectory . '/' . $bucket;
                if ($index % 1000 === 0 && !mkdir($target, 0o700)) {
                    throw new \RuntimeException('cannot create request directory');
                }
                $name = sprintf('request-%08d-seed-%d', $index + 1, $requestSeed);
                $path = 'clients/' . $client . '/' . $bucket . '/' . $name . '.php';
                $source = RequestScript::source($this->autoload, [...$workload, '--persistent', '--seed=' . $requestSeed], $name);
                Server::write($target . '/' . $name . '.tmp', $source);
                if (!rename($target . '/' . $name . '.tmp', $directory . '/' . $path)) {
                    throw new \RuntimeException('cannot publish request script');
                }
                echo "Request " . ($index + 1) . " seed={$requestSeed} path={$path}\n";
                $code = $http->run($path, $directory . '/' . $path . '.http', $signal);
                if ($code !== 0) {
                    return $code;
                }
                $until = microtime(true) + $idle / 1000;
                while ($signal() === 0 && microtime(true) < $until) {
                    usleep(10000);
                }
            }
            return $code = $signal() === 0 ? 0 : 128 + $signal();
        } catch (\Throwable $error) {
            $code = $error->getCode() === ExitCode::TIMEOUT ? ExitCode::TIMEOUT : ExitCode::FAILURE;
            fwrite(STDERR, $error->getMessage() . "\n");
            return $code;
        } finally {
            foreach ($handlers as $signalNumber => $handler) {
                pcntl_signal($signalNumber, $handler);
            }
            pcntl_async_signals($previousAsync);
            Server::write($clientDirectory . '/exit.json', json_encode(['exit' => $code, 'stopped' => date(DATE_ATOM)], JSON_THROW_ON_ERROR) . "\n");
        }
    }

    private const HELP = <<<'HELP'
redis-client-tester-sapi - long-lived PHP server and continuous request clients

Usage:
  redis-client-tester-sapi serve [server options]
  redis-client-tester-sapi client --directory PATH [client options] -- [workload options]

Server options:
  --workers N           PHP_CLI_SERVER_WORKERS, 2..128 (4; PHP also serves in parent)
  --directory PATH      Fresh private run directory (default: new temp directory)
  --php-arg ARG         Repeatable PHP startup token, e.g. -drelay.maxmemory=128m
  --php-ini FILE        Server PHP ini file
  --server-prefix ARG   Repeatable wrapper token, e.g. valgrind
  --seed N              Seed for server startup templates (0)
  --request-timeout N   Readiness/shutdown deadline in seconds (30)
  --history N           Recent completed requests in the TUI, 1..1000 (10)
  --no-tui              Plain events and periodic uptime/request totals
  --daemon              Detach; write events and totals to supervisor.log

Client options (before --):
  --directory PATH      The directory printed by serve; same machine/user required
  --requests N          Request limit, 0 runs until interrupted (0)
  --idle-ms N           Delay between requests, 0..60000 (100)
  --request-timeout N   Wall-clock deadline per HTTP request in seconds (30)
  --help                Show this help (also available on serve)

All normal workload filters, flags, weights, clients, TLS, hooks, serializers,
cluster and invocation settings follow --. --steps/--seconds apply per request.
--seed must be 0..2147483647; later request seeds are derived deterministically.
Persistent connections are enabled. --forks, --script-log and --help are reserved.
Run multiple clients for concurrent traffic; each gets an isolated directory.
Clients print each response and stop on the first failed or timed-out request.
The server remains available until interrupted or a supervised process dies.

Linux /proc, setsid, pcntl and posix are required. HTTP binds only 127.0.0.1
on an automatic port, checks a private token, and accepts generated paths only.
No request parameters change workload configuration. Never expose this server.
Ctrl+C or SIGTERM stops the server and its workers; use the printed PID for a daemon.
Stopping a client does not cancel PHP already running on the server.

Scripts, concrete .calls.php logs, raw .http responses, request events, startup
settings and server logs are retained, including failures and partial responses.
Client capture and server monitoring use bounded memory; disk use grows with
traffic. Monitor free space for long runs. No automatic pruning or server restart hides evidence of a defect.

HELP;
}
