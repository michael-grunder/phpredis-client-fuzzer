<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

/**
 * Runs a workload concurrently in `pcntl_fork()`ed children.
 *
 * The parent only supervises: every unit of work happens in a child that exits
 * as soon as it is done, so a campaign never keeps forking from a process that
 * is itself executing commands. Each child writes its formatted result to a
 * private socket rather than the inherited output stream, and the parent
 * relays those bytes only once the child has closed its end, so concurrent
 * results are never interleaved mid-document.
 */
final class ForkPool
{
    /** The largest child count a forked run accepts. */
    public const MAX_FORKS = 1024;

    private const READ_CHUNK = 65536;

    private const SELECT_TIMEOUT_SECONDS = 1;

    /** @var array<int, array{pid: int, stream: resource}> */
    private array $children = [];

    private int $stopSignals = 0;

    /**
     * @param resource $output Collected child output.
     * @param resource $error Supervision diagnostics.
     */
    public function __construct(private $output, private $error)
    {
    }

    public static function supported(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_wifexited');
    }

    /**
     * Forks `$forks` children, collects their output, and waits for all of them.
     *
     * @param positive-int $forks
     * @param callable(int, resource): int $work Receives the zero-based child
     *        index and the stream the child must write its result to, and
     *        returns that child's exit status.
     * @return int The most severe child exit status.
     */
    public function run(int $forks, callable $work): int
    {
        if (!self::supported()) {
            throw new \RuntimeException('Forked runs require the pcntl extension');
        }

        $status = ExitCode::SUCCESS;
        $this->installSignalHandlers();

        for ($index = 0; $index < $forks; $index++) {
            $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                $this->report(sprintf('could not create a result socket for child %d', $index + 1));
                $status = ExitCode::FAILURE;
                break;
            }
            [$read, $write] = $pair;

            $pid = pcntl_fork();
            if ($pid === -1) {
                fclose($read);
                fclose($write);
                $this->report(sprintf('pcntl_fork() failed before starting child %d', $index + 1));
                $status = ExitCode::FAILURE;
                break;
            }

            if ($pid === 0) {
                $this->child($index, $read, $write, $work);
            }

            fclose($write);
            $this->children[$index] = ['pid' => $pid, 'stream' => $read];
        }

        $this->collect();

        return max($status, $this->wait());
    }

    /**
     * Runs one child's work and exits; this never returns to the fork loop.
     *
     * @param resource $read The parent's end of this child's result socket.
     * @param resource $write The child's end of its result socket.
     * @param callable(int, resource): int $work
     * @return never
     */
    private function child(int $index, $read, $write, callable $work)
    {
        $this->restoreSignalHandlers();

        // Close the parent's end of this child's socket and the ends of every
        // sibling forked before it, so the only descriptors the child keeps
        // are the ones it actually writes to.
        fclose($read);
        foreach ($this->children as $sibling) {
            fclose($sibling['stream']);
        }
        $this->children = [];

        $status = ExitCode::FAILURE;
        try {
            $status = $work($index, $write);
        } catch (\Throwable $throwable) {
            $this->report(sprintf('child %d: %s', $index + 1, $throwable->getMessage()));
        }

        fclose($write);
        exit($status);
    }

    /**
     * Reads every child's result, writing each one out whole when its socket
     * closes so that concurrent results stay separate documents.
     */
    private function collect(): void
    {
        /** @var array<int, resource> $open */
        $open = [];

        /** @var array<int, string> $buffers */
        $buffers = [];

        foreach ($this->children as $index => $child) {
            $open[$index] = $child['stream'];
            $buffers[$index] = '';
        }

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, self::SELECT_TIMEOUT_SECONDS);
            if ($ready === false || $ready === 0) {
                // A signal interrupted the wait, or nothing arrived in time.
                continue;
            }

            foreach ($read as $stream) {
                $index = array_search($stream, $open, true);
                if (!is_int($index)) {
                    continue;
                }

                $chunk = fread($stream, self::READ_CHUNK);
                if ($chunk === false || $chunk === '') {
                    fclose($stream);
                    unset($open[$index]);
                    fwrite($this->output, $buffers[$index]);
                    unset($buffers[$index]);
                    continue;
                }

                $buffers[$index] .= $chunk;
            }
        }
    }

    /** @return int The most severe child exit status. */
    private function wait(): int
    {
        $status = ExitCode::SUCCESS;

        foreach ($this->children as $index => $child) {
            $state = $this->reap($child['pid']);
            if ($state === null) {
                $this->report(sprintf(
                    'could not wait for child %d (pid %d)',
                    $index + 1,
                    $child['pid'],
                ));
                $status = max($status, ExitCode::FAILURE);
                continue;
            }

            // A signalled child is a crash, a timeout, or an interrupt; it is
            // never an ordinary reply, so it fails the run and says why.
            if (pcntl_wifsignaled($state)) {
                $this->report(sprintf(
                    'child %d (pid %d) terminated by signal %d',
                    $index + 1,
                    $child['pid'],
                    pcntl_wtermsig($state),
                ));
                $status = max($status, ExitCode::FAILURE);
                continue;
            }

            $status = max($status, $this->exitStatus($state));
        }

        $this->children = [];

        return $status;
    }

    /**
     * Waits for one child, retrying a wait a signal interrupted.
     *
     * @return int|null The raw wait status, or null when the child could not
     *         be reaped.
     */
    private function reap(int $pid): ?int
    {
        $state = 0;
        do {
            $result = pcntl_waitpid($pid, $state);
        } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);

        if ($result === -1 || !is_int($state)) {
            return null;
        }

        return $state;
    }

    /** Maps a child wait status onto the documented fuzzer exit codes. */
    private function exitStatus(int $state): int
    {
        if (!pcntl_wifexited($state)) {
            return ExitCode::FAILURE;
        }

        $code = pcntl_wexitstatus($state);

        return match ($code) {
            ExitCode::SUCCESS, ExitCode::STARTUP => $code,
            default => ExitCode::FAILURE,
        };
    }

    /**
     * Handlers are installed before the first fork so an interrupt can never
     * land in the window between forking a child and recording its pid.
     */
    private function installSignalHandlers(): void
    {
        if (!$this->canHandleSignals()) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function (int $signal): void {
            $this->stopSignals++;
            foreach ($this->children as $child) {
                posix_kill($child['pid'], $signal);
            }

            // A second interrupt means the children are not going down; stop
            // supervising and take the default action for the signal.
            if ($this->stopSignals > 1) {
                pcntl_signal($signal, SIG_DFL);
                posix_kill(posix_getpid(), $signal);
            }
        };

        foreach ($this->stopSignalNumbers() as $signal) {
            pcntl_signal($signal, $handler);
        }
    }

    /** Children supervise nothing, so they take the default signal actions. */
    private function restoreSignalHandlers(): void
    {
        if (!$this->canHandleSignals()) {
            return;
        }

        foreach ($this->stopSignalNumbers() as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
    }

    private function canHandleSignals(): bool
    {
        return function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
            && function_exists('posix_kill')
            && function_exists('posix_getpid');
    }

    /** @return list<int> */
    private function stopSignalNumbers(): array
    {
        $signals = [];
        if (defined('SIGINT')) {
            $signals[] = SIGINT;
        }
        if (defined('SIGTERM')) {
            $signals[] = SIGTERM;
        }

        return $signals;
    }

    private function report(string $message): void
    {
        fwrite($this->error, 'phpredis-fuzz: ' . $message . "\n");
    }
}
