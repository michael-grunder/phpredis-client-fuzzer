<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Harness;

/** A background gdb capture; output goes straight to disk, including partial failures. */
final class CoreBacktrace
{
    private float $deadline;

    /** @param resource $process
     *  @param resource $output
     */
    private function __construct(private $process, private $output)
    {
        $this->deadline = microtime(true) + 120;
    }

    public static function path(string $core): string
    {
        return dirname($core) . '/backtrace.' . basename($core) . '.txt';
    }

    public static function start(string $core, string $php): ?self
    {
        $path = self::path($core);
        if (file_exists($path) || is_link($path)) {
            return null;
        }
        $output = @fopen($path, 'x');
        if ($output === false) {
            fwrite(STDERR, "cannot create core backtrace: {$path}\n");
            return null;
        }
        $argv = ['gdb', '--batch', '--nx', '--quiet',
            '-iex', 'set auto-load off', '-iex', 'set debuginfod enabled off',
            '-ex', 'set pagination off', '-ex', 'thread apply all bt full',
            '--se=' . $php, '--core=' . $core];
        fwrite($output, ReproStore::formatCommand($argv) . "\n\n");
        $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        if (!is_resource($process)) {
            fwrite($output, "\n[gdb: cannot start gdb (requires gdb on PATH)]\n");
            fclose($output);
            return null;
        }
        return new self($process, $output);
    }

    /** Reap without blocking worker supervision. */
    public function poll(): bool
    {
        if (!is_resource($this->process)) {
            return true;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            if (microtime(true) < $this->deadline) {
                return false;
            }
            $this->stop('timed out after 120 seconds; partial output retained');
            return true;
        }
        $message = $status['signaled'] ? 'terminated by signal ' . $status['termsig'] : 'exit ' . $status['exitcode'];
        if ($status['exitcode'] === 127) {
            $message .= ' (cannot execute gdb; check PATH)';
        }
        $this->close($message);
        return true;
    }

    /** @param (\Closure(): void)|null $heartbeat */
    public function finish(?\Closure $heartbeat = null): void
    {
        try {
            while (!$this->poll()) {
                $heartbeat?->__invoke();
                usleep(50000);
            }
        } finally {
            $this->stop('interrupted; partial output retained');
        }
    }

    public function __destruct()
    {
        $this->stop('interrupted; partial output retained');
    }

    private function stop(string $message): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            $this->close($message);
        }
    }

    private function close(string $message): void
    {
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
        if (is_resource($this->output)) {
            fwrite($this->output, "\n[gdb: {$message}]\n");
            fclose($this->output);
        }
    }
}
