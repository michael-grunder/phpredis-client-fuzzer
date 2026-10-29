<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Cli;

use Mgrunder\RedisClientTester\Harness\PhpStartupTemplate;
use Mgrunder\RedisClientTester\Harness\ReproStore;
use Mgrunder\RedisClientTester\Sapi\Request;
use Mgrunder\RedisClientTester\Sapi\RequestScript;
use Mgrunder\RedisClientTester\Sapi\Server;
use Mgrunder\RedisClientTester\Sapi\ServerDiscovery;
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
        $server = null;
        $json = false;
        try {
            $mode = array_shift($arguments);
            if ($mode === 'info' || $mode === 'list') {
                return $this->inspect($mode, $arguments);
            }
            if ($mode === 'client') {
                return $this->client($arguments);
            }
            if ($mode !== 'serve') {
                throw new \InvalidArgumentException('expected serve, client, info or list');
            }
            $options = Options::parse($arguments,
                ['workers', 'directory', 'php-arg', 'php-ini', 'server-prefix', 'request-timeout', 'history', 'seed'],
                ['help', 'daemon', 'no-tui', 'json', 'stop-on-worker-exit'], ['php-arg', 'server-prefix']);
            if ($options->has('help')) {
                echo self::HELP;
                return 0;
            }
            $json = $options->has('json');
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
            $tui = !$json && !$options->has('no-tui') && stream_isatty(STDOUT);
            $monitor = new SoakMonitor($directory, $history, $tui, $json);
            $discovery = new ServerDiscovery(sys_get_temp_dir());
            $connection = $this->clientCommand($directory);
            $tag = $discovery->tag($directory);
            $published = false;
            $started = true;
            $server = new Server($directory, $workers, 1, 0, $timeout, !$tui && !$json,
                stopOnWorkerExit: $options->has('stop-on-worker-exit'));
            try {
                $code = $server->run(
                    [], $phpArgs, $options->repeated('server-prefix', splitCommas: false),
                    function (string $address, string $token) use ($directory, $workers, $phpArgs, $seed, $monitor, $connection, $discovery, $tag, $tui, $json, $server, &$published): bool {
                        if (!$published) {
                            $metadata = json_encode([
                                'address' => $address, 'token' => $token, 'pid' => getmypid(), 'workers' => $workers,
                                'php' => PHP_BINARY, 'php_args' => $phpArgs, 'seed' => $seed, 'started' => date(DATE_ATOM),
                            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                            Server::write($directory . '/connection.json.tmp', $metadata);
                            if (!rename($directory . '/connection.json.tmp', $directory . '/connection.json')) {
                                throw new \RuntimeException('cannot publish server connection');
                            }
                            $discovery->publish($directory);
                            Server::write($directory . '/server.ready', $discovery->id($directory) . "\n");
                            if ($json) {
                                echo json_encode(['type' => 'ready', 'server' => $tag, 'directory' => $directory,
                                    'pid' => getmypid(), 'workers' => $workers, 'client' => $connection], JSON_THROW_ON_ERROR) . "\n";
                            } elseif (!$tui) {
                                echo "SAPI ID: {$tag}\nLatest: " . $discovery->latestPath() . "\n";
                                echo "SAPI artifacts: {$directory}\nClient: {$connection}\nStop: kill -TERM " . getmypid() . "\n";
                            }
                            $published = true;
                        }
                        $monitor->render($tag, pool: $server->snapshot());
                        return true;
                    },
                );
            } finally {
                try {
                    $monitor->render($tag, true, $server->snapshot());
                } finally {
                    $monitor->stop();
                }
            }
            $this->finish($directory, $code, $server, $json);
            return $code;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'redis-client-tester-sapi: ' . $error->getMessage() . "\n");
            $code = $started ? ExitCode::FAILURE : ExitCode::STARTUP;
            if (!$detached && $directory !== null && !is_file($directory . '/exit.json')) {
                $this->finish($directory, $code, $server, $json, $error->getMessage());
            }
            return $code;
        }
    }

    private function finish(string $directory, int $code, ?Server $server, bool $json, ?string $error = null): void
    {
        $report = ['exit' => $code, 'stopped' => date(DATE_ATOM), 'error' => $error] + ($server?->report() ?? []);
        Server::write($directory . '/exit.json', json_encode($report,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT) . "\n");
        $guidance = "Diagnostics retained in {$directory}\n"
            . "  Exit report (reason and worker states): {$directory}/exit.json\n"
            . "  Process history (exits and cleanup signals): {$directory}/processes.jsonl\n"
            . "  Full PHP stderr: {$directory}/server.stderr.log\n"
            . "  Full PHP stdout: {$directory}/server.stdout.log\n"
            . "  Request history (when requests ran): {$directory}/requests.jsonl\n";
        if ($json) {
            echo json_encode(['type' => 'exit', 'directory' => $directory] + $report,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            fwrite(STDERR, $guidance);
            return;
        }
        $reason = $error ?? $report['pool']['reason'] ?? 'server did not start';
        fwrite(STDERR, "SAPI stopped: exit {$code}; {$reason}\n");
        $before = $report['before_shutdown'] ?? null;
        if ($before !== null) {
            fwrite(STDERR, sprintf("Before cleanup: workers %d/%d; parent %s\n", $before['workers_alive'],
                $before['workers_expected'], $before['parent_alive'] ? 'up' : 'down'));
        }
        foreach ($report['pool']['processes'] ?? [] as $process) {
            fwrite(STDERR, sprintf("  %s PID %d: %s, exit=%s signal=%s wait=%s core=%s [%s; %s]\n",
                $process['role'], $process['pid'], $process['state'],
                json_encode($process['exit_code']), json_encode($process['signal']), json_encode($process['wait_status']),
                json_encode($process['core_dumped']), $process['phase'], $process['source']));
        }
        if ($code !== 0 && !in_array($code, [128 + SIGINT, 128 + SIGTERM], true) && ($report['stderr_tail'] ?? '') !== '') {
            fwrite(STDERR, "Last server stderr (up to 8 KiB):\n" . $report['stderr_tail'] . "\n");
        }
        fwrite(STDERR, $guidance);
    }

    /** @param list<string> $arguments */
    private function daemon(string $directory, array $arguments, float $timeout): int
    {
        $json = in_array('--json', $arguments, true);
        // Re-exec with file descriptors redirected before creating the server process group.
        $source = 'require $argv[1]; exit((new \\Mgrunder\\RedisClientTester\\Cli\\SapiSoakApplication($argv[1]))->run(array_slice($argv, 3), $argv[2]));';
        $args = array_values(array_filter($arguments, static fn (string $arg): bool => $arg !== '--daemon'));
        $process = proc_open(['/usr/bin/setsid', '--fork', PHP_BINARY, '-r', $source,
            $this->autoload, $directory, 'serve', ...$args, '--no-tui'], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/supervisor.log', 'a'],
                2 => ['file', $directory . ($json ? '/supervisor.stderr.log' : '/supervisor.log'), 'a'],
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
            if (is_file($directory . '/server.ready')) {
                $pid = trim((string) file_get_contents($directory . '/supervisor.pid'));
                $discovery = new ServerDiscovery(sys_get_temp_dir());
                if ($json) {
                    echo json_encode(['type' => 'daemon', 'server' => $discovery->tag($directory), 'pid' => (int) $pid,
                        'directory' => $directory, 'log' => $directory . '/supervisor.log',
                        'client' => $this->clientCommand($directory)], JSON_THROW_ON_ERROR) . "\n";
                } else {
                    echo "SAPI ID: " . $discovery->tag($directory) . "\nLatest: " . $discovery->latestPath() . "\n";
                    echo "SAPI daemon PID: {$pid}\nArtifacts and log: {$directory}\nClient: " . $this->clientCommand($directory) . "\nStop: kill -TERM {$pid}\n";
                }
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
            'client', '--server=' . (new ServerDiscovery(sys_get_temp_dir()))->tag($directory), '--']) . ' [workload options]';
    }

    /** @param list<string> $arguments */
    private function inspect(string $mode, array $arguments): int
    {
        if ($mode === 'info' && isset($arguments[0]) && !str_starts_with($arguments[0], '--')) {
            $arguments[0] = '--server=' . $arguments[0];
        }
        $options = Options::parse($arguments, $mode === 'info' ? ['server', 'directory'] : [], ['help']);
        if ($options->has('help')) {
            echo self::HELP;
            return 0;
        }
        Server::assertSupported();
        if ($options->has('directory') && $options->has('server')) {
            throw new \InvalidArgumentException('use either --directory or --server');
        }
        $discovery = new ServerDiscovery(sys_get_temp_dir());
        $directories = $mode === 'list' ? $discovery->directories() : [
            $options->nullableString('directory') ?? $discovery->resolve($options->nullableString('server')),
        ];
        $count = 0;
        foreach ($directories as $selected) {
            try {
                $directory = realpath($selected);
                $json = $directory === false ? false : @file_get_contents($directory . '/connection.json', length: 65536);
                $metadata = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if ($directory === false || !is_array($metadata) || !is_int($metadata['pid'] ?? null)
                    || !is_string($metadata['address'] ?? null)) {
                    throw new \RuntimeException('missing or invalid server metadata: ' . $selected);
                }
            } catch (\RuntimeException|\JsonException $error) {
                if ($mode === 'info') {
                    throw $error;
                }
                continue;
            }
            $running = $discovery->isRunning($directory, $metadata['pid']);
            if ($mode === 'list' && !$running) {
                continue;
            }
            $count++;
            echo 'Server: ' . $discovery->tag($directory) . ' (' . ($running ? 'running' : 'stopped') . ")\n";
            echo 'ID: ' . $discovery->id($directory) . "\nDirectory: {$directory}\n";
            echo 'Address: http://' . $metadata['address'] . "\nPID: " . $metadata['pid'] . "\n";
            foreach (['started' => 'Started', 'workers' => 'Workers', 'php' => 'PHP', 'php_args' => 'PHP arguments', 'seed' => 'Startup seed'] as $key => $label) {
                echo $label . ': ' . json_encode($metadata[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            }
            echo "Logs: {$directory}/server.stdout.log, {$directory}/server.stderr.log\n";
            echo "Requests: {$directory}/requests.jsonl\nProcesses: {$directory}/processes.jsonl\nExit report: {$directory}/exit.json\n";
            if (is_file($directory . '/supervisor.log')) {
                echo "Supervisor log: {$directory}/supervisor.log\n";
            }
            if (is_file($directory . '/supervisor.stderr.log')) {
                echo "Supervisor errors: {$directory}/supervisor.stderr.log\n";
            }
            echo 'Client: ' . $this->clientCommand($directory) . "\n";
            if ($running) {
                echo 'Stop: kill -TERM ' . $metadata['pid'] . "\n";
            }
            echo "\n";
        }
        if ($count === 0) {
            echo "No running SAPI servers found for this user in " . sys_get_temp_dir() . ".\n";
        }
        return 0;
    }

    /** @param list<string> $arguments */
    private function client(array $arguments): int
    {
        $separator = array_search('--', $arguments, true);
        $options = Options::parse($separator === false ? $arguments : array_slice($arguments, 0, $separator),
            ['directory', 'server', 'requests', 'idle-ms', 'request-timeout'], ['help']);
        if ($options->has('help')) {
            echo self::HELP;
            return 0;
        }
        if ($options->has('directory') && $options->has('server')) {
            throw new \InvalidArgumentException('use either --directory or --server');
        }
        Server::assertSupported();
        $workload = $separator === false ? [] : array_slice($arguments, $separator + 1);
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
        $selected = $options->nullableString('directory')
            ?? (new ServerDiscovery(sys_get_temp_dir()))->resolve($options->nullableString('server'));
        $directory = realpath($selected);
        if ($directory === false || !is_dir($directory . '/clients') || is_file($directory . '/exit.json')) {
            throw new \InvalidArgumentException("SAPI server directory is unavailable or stopped: {$selected}; start serve or select another --server/--directory");
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
  redis-client-tester-sapi client [client options] [-- workload options]
  redis-client-tester-sapi info [ID | --server ID | --directory PATH]
  redis-client-tester-sapi list

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
  --json                JSON Lines: ready/request/status/exit events, no TUI
  --stop-on-worker-exit  Stop the remaining pool on the first worker exit (off)

Client options (before --):
  --directory PATH      Explicit server directory (mutually exclusive with --server)
  --server ID           Short tag or full server ID; default: latest ready server
  --requests N          Request limit, 0 runs until interrupted (0)
  --idle-ms N           Delay between requests, 0..60000 (100)
  --request-timeout N   Wall-clock deadline per HTTP request in seconds (30)
  --help                Show this help (also available on serve)

The TUI shows a 12-character server tag. info defaults to latest and prints
full paths, startup settings, logs and a client command; list shows all running
servers for this user/temp directory. Inspection does not send HTTP workloads.

Serve publishes path pointers in the temp directory as rct-sapi-UID-HASH and
rct-sapi-UID-latest (normally /tmp). Clients without a selector use latest.
Pointers remain after shutdown; stale servers fail rather than silently switching.
Server and client must use the same user and temp directory.

All normal workload filters, flags, weights, clients, TLS, hooks, serializers,
cluster and invocation settings follow --. --steps/--seconds apply per request.
--seed must be 0..2147483647; later request seeds are derived deterministically.
Persistent connections are enabled. --forks, --script-log and --help are reserved.
Run multiple clients for concurrent traffic; each gets an isolated directory.
Clients print each response and stop on the first failed or timed-out request.
The server remains available after worker exits while the PHP parent is alive.

Linux /proc, setsid, pcntl and posix are required. HTTP binds only 127.0.0.1
on an automatic port, checks a private token, and accepts generated paths only.
No request parameters change workload configuration. Never expose this server.
Ctrl+C or SIGTERM stops the server and its workers; use the printed PID for a daemon.
Stopping a client does not cancel PHP already running on the server.
The header counts live forked workers separately from the serving parent. With
server-prefix wrappers, worker counts include other tracked descendants.
Worker exits are recorded without stopping or restarting the remaining pool.
Use --stop-on-worker-exit to stop on the first worker exit, including exit code 0.
Parent exit always stops the supervisor and remaining workers. processes.jsonl
records observations and requested cleanup signals. exit.json retains the first
reason, before/after worker states and the last 8 KiB of server stderr. Unknown
exit statuses stay null; a missing/reaped process is not labelled a crash.
A final report is printed after leaving the TUI. --json writes it as an exit event.
On exit, stderr explains where to find the retained reports and full server logs.

Scripts, concrete .calls.php logs, raw .http responses, request events, startup
settings and server logs are retained, including failures and partial responses.
Client capture and server monitoring use bounded memory; disk use grows with
traffic. Monitor free space for long runs. No automatic pruning or server restart hides evidence of a defect.

HELP;
}
