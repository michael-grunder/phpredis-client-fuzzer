<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

use Mgrunder\RedisClientTester\Cli\ExitCode;
use Mgrunder\RedisClientTester\Harness\ReproStore;
use Mgrunder\RedisClientTester\Harness\SanitizerReport;

/** One server lifetime, including concurrent requests, idle periods and worker supervision. */
final class Server
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $log = null;
    /** @var array<int, true> */
    private array $pids = [];
    private int $pid = 0;
    private int $failure = 0;
    private int $stopped = 0;
    private string $address = '';
    private string $token;

    public function __construct(
        private readonly string $directory,
        private readonly int $workers,
        private readonly int $concurrency,
        private readonly int $idleMs,
        private readonly float $timeout,
        private readonly bool $forwardOutput = true,
    ) {
        if ($workers < 2 || $workers > 128 || $concurrency < 1 || $concurrency > $workers
            || $idleMs < 0 || $idleMs > 60000 || !is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('invalid SAPI server limits');
        }
        $this->token = hash('sha256', $directory);
    }

    public static function assertSupported(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc/self')
            || !function_exists('pcntl_signal') || !function_exists('posix_kill')
            || !is_executable('/usr/bin/setsid')) {
            throw new \RuntimeException('SAPI runs require Linux /proc, pcntl, posix and /usr/bin/setsid');
        }
    }

    public static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new \RuntimeException("cannot write {$path}");
        }
    }

    /**
     * @internal Harness fallback when the supervisor itself crashes or is killed.
     * @param (\Closure(): void)|null $heartbeat
     */
    public static function stopOrphans(string $directory, bool $force = false, ?\Closure $heartbeat = null): void
    {
        if (!function_exists('posix_kill')) {
            return;
        }
        $pids = @file($directory . '/pids.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($pids === false || !isset($pids[0]) || !ctype_digit($pids[0]) || (int) $pids[0] <= 1) {
            return;
        }
        $group = (int) $pids[0];
        // A parent can die before tick() records its workers; find surviving group members too.
        $stats = glob('/proc/[0-9]*/stat') ?: [];
        foreach ($stats as $path) {
            $stat = @file_get_contents($path);
            if ($stat === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
            if ($fields[0] !== 'Z' && ($fields[2] ?? '') === (string) $group && ($fields[3] ?? '') === (string) $group) {
                if ($force) {
                    @posix_kill(-$group, SIGKILL);
                    return;
                }
                @posix_kill(-$group, SIGTERM);
                $deadline = microtime(true) + 0.5;
                while (@posix_kill(-$group, 0) && microtime(true) < $deadline) {
                    $heartbeat?->__invoke();
                    usleep(10000);
                }
                @posix_kill(-$group, SIGKILL);
                return;
            }
        }
    }

    /**
     * @param list<string> $paths
     * @param list<string> $phpArgs
     * @param list<string> $prefix
     * @param (\Closure(string, string): bool)|null $heartbeat Keep serving while this returns true.
     */
    public function run(array $paths, array $phpArgs = [], array $prefix = [], ?\Closure $heartbeat = null): int
    {
        self::assertSupported();
        if ($heartbeat !== null) {
            $this->token = bin2hex(random_bytes(32));
        }
        $router = "<?php\n"
            . 'if (($_SERVER["HTTP_X_FUZZER_TOKEN"] ?? "") !== ' . var_export($this->token, true)
            . ') { http_response_code(403); return; }' . "\n"
            . 'header("X-Fuzzer-Run: ' . $this->token . '");' . "\n"
            . 'header("X-Fuzzer-Pid: " . getmypid());' . "\n"
            . 'if ($_SERVER["REQUEST_URI"] === "/ready") { header("X-Fuzzer-Exit: 0"); echo "ready"; return; }' . "\n";
        $router .= $heartbeat !== null ? SoakRouter::source()
            : '$paths = ' . var_export(array_map(static fn (string $path): string => '/' . $path, $paths), true) . ";\n"
                . 'if (!in_array($_SERVER["REQUEST_URI"], $paths, true)) { http_response_code(404); return; }' . "\n"
                . 'require __DIR__ . $_SERVER["REQUEST_URI"];' . "\n";
        self::write($this->directory . '/router.php', $router);
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException("cannot allocate HTTP port: {$error}");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new \RuntimeException('cannot read allocated HTTP port');
        }
        $this->address = $address;
        $argv = ['/usr/bin/setsid', ...$prefix, PHP_BINARY, ...$phpArgs,
            '-S', $address, '-t', $this->directory, $this->directory . '/router.php'];
        self::write($this->directory . '/server-command.txt', 'PHP_CLI_SERVER_WORKERS=' . $this->workers
            . ' ' . ReproStore::formatCommand($argv) . "\n");
        $env = getenv();
        $env['PHP_CLI_SERVER_WORKERS'] = (string) $this->workers;
        $previousAsync = pcntl_async_signals(true);
        $handlers = [];
        foreach ([SIGINT, SIGTERM] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (int $received): void { $this->stopped = $received; });
        }
        try {
            $code = $this->serve($paths, $argv, $env, $heartbeat);
        } catch (\RuntimeException $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            $code = $error->getCode() === ExitCode::TIMEOUT ? ExitCode::TIMEOUT : ExitCode::FAILURE;
        } finally {
            $shutdownCode = $this->stop();
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($previousAsync);
        }
        // Scan incrementally: long-lived servers can accumulate weeks of diagnostics.
        $reportedError = SanitizerReport::scan($this->directory . '/server.stderr.log') !== null;
        $diagnostics = @fopen($this->directory . '/server.stderr.log', 'r');
        if ($diagnostics !== false) {
            while (($line = fgets($diagnostics, 16384)) !== false) {
                if (preg_match('/PHP (?:Fatal|Parse) error:|ERROR SUMMARY: [1-9][0-9,]* errors/', $line) === 1) {
                    $reportedError = true;
                    break;
                }
            }
            fclose($diagnostics);
        }
        $code = $this->failure ?: ($code ?: $shutdownCode);
        return $code === 0 && $reportedError ? ExitCode::FAILURE : $code;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $argv
     * @param array<string, string> $env
     * @param (\Closure(string, string): bool)|null $heartbeat
     */
    private function serve(array $paths, array $argv, array $env, ?\Closure $heartbeat): int
    {
        $process = proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . '/server.stdout.log', 'w'],
            2 => ['file', $this->directory . '/server.stderr.log', 'w'],
        ], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot launch PHP server');
        }
        $this->process = $process;
        $this->pid = proc_get_status($this->process)['pid'];
        self::write($this->directory . '/pids.txt', $this->pid . "\n");
        $log = fopen($this->directory . '/server.stderr.log', 'r');
        if ($log === false) {
            throw new \RuntimeException('cannot read server diagnostics');
        }
        $this->log = $log;
        $deadline = microtime(true) + $this->timeout;
        $ready = false;
        do {
            $this->tick();
            if ($this->failure !== 0 || $this->stopped !== 0) {
                return $this->failure ?: 128 + $this->stopped;
            }
            $probe = @stream_socket_client('tcp://' . $this->address, $errno, $error, 0.05);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (!$ready) {
            throw new \RuntimeException('PHP server readiness timed out', ExitCode::TIMEOUT);
        }
        // Verify identity before sending any workload to a potentially raced port.
        $code = $this->batch(['ready']);
        if ($code !== 0) {
            return $code;
        }
        if ($heartbeat !== null) {
            do {
                $this->tick();
                if ($this->failure !== 0 || $this->stopped !== 0) {
                    return $this->failure ?: 128 + $this->stopped;
                }
                if (!$heartbeat($this->address, $this->token)) {
                    return 0;
                }
                usleep(100000);
            } while (true);
        }
        foreach (array_chunk($paths, max(1, $this->concurrency)) as $batch) {
            $code = $this->batch($batch);
            if ($code !== 0) {
                return $code;
            }
            $until = microtime(true) + $this->idleMs / 1000;
            do {
                $this->tick();
                if ($this->failure !== 0 || $this->stopped !== 0) {
                    return $this->failure ?: 128 + $this->stopped;
                }
                usleep(1000);
            } while (microtime(true) < $until);
        }
        return 0;
    }

    /** @param list<string> $paths */
    private function batch(array $paths): int
    {
        /** @var array<int, resource> $sockets */
        $sockets = [];
        /** @var array<int, resource> $responses */
        $responses = [];
        $deadline = microtime(true) + $this->timeout;
        try {
            foreach ($paths as $index => $path) {
                $socket = @stream_socket_client('tcp://' . $this->address, $errno, $error, min(1.0, $this->timeout));
                if ($socket === false) {
                    throw new \RuntimeException("HTTP connection failed: {$error}");
                }
                $sockets[$index] = $socket;
                $response = fopen($this->directory . '/' . $path . '.http', 'x+');
                if ($response === false) {
                    throw new \RuntimeException('cannot create HTTP response log');
                }
                $responses[$index] = $response;
                $request = "GET /{$path} HTTP/1.0\r\nHost: {$this->address}\r\nX-Fuzzer-Token: {$this->token}\r\nConnection: close\r\n\r\n";
                if (fwrite($socket, $request) !== strlen($request)) {
                    throw new \RuntimeException('could not send complete HTTP request');
                }
                stream_set_blocking($socket, false);
            }
            $code = 0;
            while ($sockets !== []) {
                $this->tick();
                if ($this->failure !== 0 || $this->stopped !== 0) {
                    return $this->failure ?: 128 + $this->stopped;
                }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('HTTP batch timed out', ExitCode::TIMEOUT);
                }
                $read = array_values($sockets);
                $write = $except = [];
                if (@stream_select($read, $write, $except, 0, 20000) === false) {
                    continue;
                }
                foreach ($sockets as $index => $socket) {
                    if (!in_array($socket, $read, true)) {
                        continue;
                    }
                    if (stream_copy_to_stream($socket, $responses[$index]) === false) {
                        throw new \RuntimeException('cannot capture HTTP response');
                    }
                    if (feof($socket)) {
                        fclose($socket);
                        unset($sockets[$index]);
                        rewind($responses[$index]);
                        $headers = '';
                        while (($line = fgets($responses[$index], 8192)) !== false && $line !== "\r\n") {
                            $headers .= $line;
                            if (strlen($headers) > 65536) {
                                throw new \RuntimeException('oversized HTTP headers');
                            }
                        }
                        if ((!str_starts_with($headers, 'HTTP/1.0 200 ') && !str_starts_with($headers, 'HTTP/1.1 200 '))
                            || !str_contains($headers, 'X-Fuzzer-Run: ' . $this->token . "\r\n")
                            || preg_match('/^X-Fuzzer-Exit: (0|1|78)\r$/m', $headers, $match) !== 1) {
                            $code = ExitCode::FAILURE;
                            fwrite(STDERR, "incomplete or failed HTTP request: {$paths[$index]}\n");
                        } elseif ($code === 0) {
                            $code = (int) $match[1];
                        }
                        if ($paths[$index] !== 'ready') {
                            fpassthru($responses[$index]);
                        }
                    }
                }
            }
            $this->tick();
            return $this->failure ?: $code;
        } finally {
            foreach ([...$sockets, ...$responses] as $stream) {
                fclose($stream);
            }
        }
    }

    private function tick(): void
    {
        $this->forwardLog();
        if (!is_resource($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->failure = $status['signaled'] ? 128 + $status['termsig'] : max(1, $status['exitcode']);
        }
        $queue = [$this->pid];
        $changed = false;
        while ($queue !== []) {
            $pid = array_pop($queue);
            if (!isset($this->pids[$pid])) {
                $this->pids[$pid] = true;
                $changed = true;
            }
            $children = @file_get_contents("/proc/{$pid}/task/{$pid}/children");
            foreach (explode(' ', trim($children === false ? '' : $children)) as $child) {
                if (ctype_digit($child)) {
                    $queue[] = (int) $child;
                }
            }
        }
        if ($changed) {
            self::write($this->directory . '/pids.txt', implode("\n", array_keys($this->pids)) . "\n");
        }
        foreach ($this->pids as $pid => $_) {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if ($stat === false) {
                $this->failure = $this->failure ?: ExitCode::FAILURE;
                continue;
            }
            // comm can contain spaces and parentheses; fields after its final ')' start at field 3.
            $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
            if ($fields[0] === 'Z') {
                $waitStatus = (int) ($fields[49] ?? 0);
                $signal = $waitStatus & 127;
                $this->failure = $signal > 0 ? 128 + $signal : max(1, $waitStatus >> 8);
                fwrite(STDERR, "PHP server descendant {$pid} exited (wait status {$waitStatus})\n");
            }
        }
    }

    private function forwardLog(): void
    {
        if (is_resource($this->log)) {
            $chunk = stream_get_contents($this->log, 65536);
            if ($this->forwardOutput && $chunk !== false && $chunk !== '') {
                fwrite(STDERR, $chunk);
            }
        }
    }

    private function stop(): int
    {
        $code = 0;
        if (is_resource($this->process)) {
            // The server owns its own session; wrappers and all PHP workers receive shutdown.
            if ($this->pid > 0) {
                @posix_kill(-$this->pid, SIGTERM);
            }
            proc_terminate($this->process, SIGTERM);
            $deadline = microtime(true) + ($this->stopped === 0 ? $this->timeout : 0.5);
            do {
                usleep(10000);
                $status = proc_get_status($this->process);
            } while (($status['running'] || $this->hasLiveWorkers()) && microtime(true) < $deadline);
            $code = $status['running'] || $this->hasLiveWorkers() ? ExitCode::TIMEOUT : max(0, $status['exitcode']);
            if ($status['signaled'] && !in_array($status['termsig'], [SIGTERM, SIGKILL], true)) {
                $code = 128 + $status['termsig'];
            }
            if ($this->pid > 0) {
                @posix_kill(-$this->pid, SIGKILL);
            }
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
            $this->process = null;
        }
        $this->forwardLog();
        if (is_resource($this->log)) {
            fclose($this->log);
            $this->log = null;
        }
        return $code;
    }

    private function hasLiveWorkers(): bool
    {
        foreach ($this->pids as $pid => $_) {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat !== false && substr($stat, (int) strrpos($stat, ')') + 2, 1) !== 'Z') {
                return true;
            }
        }
        return false;
    }
}
