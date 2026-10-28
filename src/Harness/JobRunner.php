<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Harness;

use Mgrunder\RedisClientTester\Cli\ExitCode;
use Mgrunder\RedisClientTester\AsanRuntime;
use Mgrunder\RedisClientTester\Sapi\Server;

/**
 * Launches fuzzer child processes, polls them without blocking, classifies how
 * they ended, and hands captured failures to the {@see ReproStore}. Also
 * exposes a synchronous {@see rerun()} used by the {@see Reducer}.
 */
final class JobRunner
{
    private int $nextId = 1;

    /** @var array<int, array{process: resource, pid: int, directory: string}> */
    private array $processes = [];

    private readonly PhpStartupTemplate $startup;

    /** @var (\Closure(): void)|null */
    private ?\Closure $heartbeat = null;

    /**
     * @param list<string> $phpArgs Startup arguments inserted between the PHP
     *        binary and the fuzzer script (`--php-args`).
     * @param string|null $setsidBinary A verified `setsid(1)` used to run each
     *        child in its own session, or null to launch children in the
     *        harness' own process group. See {@see buildArgv()}.
     * @param RunLog|null $runLog Where every finished run's stdout is appended
     *        (`--run-log`), or null to keep only what a capture retains.
     */
    public function __construct(
        private readonly HarnessOptions $options,
        private readonly string $php,
        array $phpArgs,
        private readonly string $baseDir,
        private readonly CommandTemplate $template,
        private readonly ReproStore $store,
        private readonly string $workRoot,
        private readonly ?string $rrBinary,
        private readonly string $phpVersion,
        private readonly ?string $setsidBinary = null,
        private readonly ?RunLog $runLog = null,
        ?PhpStartupTemplate $startup = null,
        private readonly ?AsanRuntime $asan = null,
    ) {
        $this->startup = $startup ?? new PhpStartupTemplate($phpArgs, $options->phpIni);
        Fs::ensureDir($this->workRoot);
    }

    /** Number of runs launched so far (spawns and reduction re-runs). */
    public function launched(): int
    {
        return $this->nextId - 1;
    }

    /**
     * Keep the view responsive during synchronous waits; must not poll jobs recursively.
     * @param (\Closure(): void)|null $heartbeat
     */
    public function setHeartbeat(?\Closure $heartbeat): void
    {
        $this->heartbeat = $heartbeat;
    }

    public function spawn(int $slot, ?int $port, int $seed): Job
    {
        $id = $this->nextId++;
        $steps = $this->options->steps;
        $workDir = sprintf('%s/run-%05d', $this->workRoot, $id);
        Fs::ensureDir($workDir);

        $traceDir = $this->options->rr ? $workDir . '/rr-trace' : null;
        $startup = $this->startup->render($seed, $workDir);
        $argv = $this->buildArgv($port, $steps, $seed, $slot, $id, $traceDir, $startup['args']);

        $process = $this->open($argv, $workDir, $startup['args']);
        $status = proc_get_status($process);

        $job = new Job(
            id: $id,
            slot: $slot,
            port: $port,
            steps: $steps,
            seed: $seed,
            workDir: $workDir,
            traceDir: $traceDir,
            argv: $argv,
            startedAt: microtime(true),
            process: $process,
            phpArgs: $startup['args'],
            phpIni: $startup['ini'],
        );
        $job->pid = $status['pid'];

        return $job;
    }

    /**
     * Advance a job's state. Cheap and non-blocking unless the job has to be
     * terminated (shutdown, or --run-timeout).
     */
    public function poll(Job $job, bool $draining = false): void
    {
        if (!$job->isRunning()) {
            return;
        }

        $terminated = false;
        $status = $this->status($job);
        if ($status['running'] === true) {
            $expired = !$draining
                && $this->options->runTimeout > 0.0
                && $job->duration() > $this->options->runTimeout;

            if (!$draining && !$expired) {
                return;
            }

            $job->killSignal = $this->terminate($job);
            $job->timedOut = $expired;
            $terminated = true;
            $status = $this->status($job);
        }

        $job->finishedAt = microtime(true);
        $job->signal = $status['signaled'] && $status['termsig'] > 0 ? $status['termsig'] : null;
        $job->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
        $this->close($job->process);

        // Only a run the shutdown actually had to kill is discarded. One that
        // had already finished on its own is a normal result — and it may be a
        // crash that landed in the last moments of the campaign, which is
        // exactly the kind of finding worth keeping.
        if ($draining && $terminated) {
            $job->status = JobStatus::Skipped;
            $job->note = 'stopped';
            // Whatever it managed to print before the shutdown reached it. The
            // block is labelled `stopped` so a truncated one is recognisable.
            $this->runLog?->append($job, 'stopped');
            $this->discard($job);
            return;
        }

        if ($this->options->capturesLeaks()) {
            $job->leak = LeakReport::scan($job->workDir . '/stderr.log');
        }

        $job->sanitizer = SanitizerReport::scan($job->workDir . '/stderr.log');
        $job->memory = MemoryReport::scan($job->workDir . '/stderr.log', $job->workDir . '/stdout.log');

        $verdict = FailureClassifier::fromExit(
            $status['signaled'],
            $job->signal,
            $job->exitCode,
            $job->timedOut,
            $job->leak !== null,
            $job->memory !== null,
            $job->sanitizer !== null,
        );

        // Before resolve(), which moves this run's stdout into a reproducer or
        // deletes it with the work directory.
        $this->runLog?->append($job, $this->runLogLabel($job, $verdict));

        $this->resolve($job, $verdict);
    }

    /**
     * Run the template once, synchronously, with a fixed seed/port and an
     * overridden step count. Used by the reducer.
     */
    public function rerun(int $seed, ?int $port, int $steps, float $timeout, bool $withRr): JobOutcome
    {
        $id = $this->nextId++;
        $workDir = sprintf('%s/reduce-%05d', $this->workRoot, $id);
        Fs::ensureDir($workDir);

        $traceDir = $withRr && $this->rrBinary !== null ? $workDir . '/rr-trace' : null;
        $startup = $this->startup->render($seed, $workDir);
        $argv = $this->buildArgv($port, $steps, $seed, 0, $id, $traceDir, $startup['args']);

        $started = microtime(true);
        $process = $this->open($argv, $workDir, $startup['args']);
        $deadline = $started + max(1.0, $timeout);

        $status = proc_get_status($process);
        while ($status['running'] === true) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 15);
                $grace = microtime(true) + 1.0;
                do {
                    $this->heartbeat?->__invoke();
                    usleep(20_000);
                    $status = proc_get_status($process);
                } while ($status['running'] && microtime(true) < $grace);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                usleep(50_000);
                proc_get_status($process);
                $this->close($process);

                return new JobOutcome(
                    FailureClassifier::fromExit(false, null, null, true),
                    $workDir,
                    null,
                    microtime(true) - $started,
                    null,
                );
            }
            $this->heartbeat?->__invoke();
            usleep(20_000);
            $status = proc_get_status($process);
        }
        $this->close($process);

        $signal = $status['signaled'] && $status['termsig'] > 0 ? $status['termsig'] : null;
        $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;

        if ($traceDir !== null) {
            $this->finaliseTrace($traceDir, $workDir);
        }

        $leak = $this->options->capturesLeaks()
            ? LeakReport::scan($workDir . '/stderr.log')
            : null;

        $sanitizer = SanitizerReport::scan($workDir . '/stderr.log');
        $memory = MemoryReport::scan($workDir . '/stderr.log', $workDir . '/stdout.log');

        return new JobOutcome(
            FailureClassifier::fromExit($status['signaled'], $signal, $exitCode, false, $leak !== null, $memory !== null, $sanitizer !== null),
            $workDir,
            $traceDir,
            microtime(true) - $started,
            $status['pid'],
            $leak,
            $memory,
            $sanitizer,
        );
    }

    /**
     * Wait for rr to finalise a trace and judge what this run's artifacts are
     * worth.
     *
     * Two separate things can be wrong with them. A trace that never finalised
     * cannot be replayed. And a run whose stderr holds rr's own death — a
     * `[FATAL ...]` line or its backtrace — tells us nothing about the client
     * whatever the trace looks like: the child the harness launched *is* rr,
     * so rr aborting is reported as a crash of the run. There is nothing left
     * to wait for once rr has printed a fatal error, so the `--trace-timeout`
     * budget is skipped rather than burned on such a run.
     */
    private function finaliseTrace(?string $traceDir, string $workDir): TraceVerdict
    {
        if ($traceDir === null) {
            return new TraceVerdict();
        }

        $fatal = RrTrace::fatalError($workDir . '/stderr.log');
        if ($fatal === null) {
            RrTrace::awaitComplete($traceDir, $this->options->traceTimeout, $this->heartbeat);
        }

        return new TraceVerdict($fatal, RrTrace::problem($traceDir));
    }

    public function cleanupOutcome(JobOutcome $outcome): void
    {
        if ($this->options->keepWork) {
            return;
        }
        Fs::removeTree($outcome->workDir);
    }

    /**
     * @return array{
     *     command: string, pid: int, running: bool, signaled: bool,
     *     stopped: bool, exitcode: int, termsig: int, stopsig: int
     * }
     */
    private function status(Job $job): array
    {
        if ($job->reapedStatus !== null) {
            return $job->reapedStatus;
        }

        $status = proc_get_status($job->process);
        if ($status['running'] === false) {
            $job->reapedStatus = $status;
        }

        return $status;
    }

    /**
     * Escalating termination: SIGTERM first for a clean shutdown, then SIGKILL
     * if the child is still alive after the grace period. Returns the signal
     * that actually ended it — 15 when it stopped politely, 9 when it had to be
     * forced.
     */
    private function terminate(Job $job): int
    {
        proc_terminate($job->process, 15);
        if ($this->waitForExit($job, 3.0)) {
            return 15;
        }
        proc_terminate($job->process, 9);
        $this->waitForExit($job, 2.0);

        return 9;
    }

    private function waitForExit(Job $job, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($this->status($job)['running'] === false) {
                return true;
            }
            $this->heartbeat?->__invoke();
            usleep(50_000);
        }

        return $this->status($job)['running'] === false;
    }

    private function resolve(Job $job, FailureClassifier $verdict): void
    {
        $job->failure = $verdict->failed;

        if (!$verdict->failed) {
            $job->status = JobStatus::Passed;
            $job->note = 'ok';
            $this->discard($job);
            return;
        }

        $kind = $verdict->kind();

        // A rejected command line is not a finding: nothing was fuzzed, and
        // every further run would be rejected the same way. Keep the child's
        // own diagnostic so the scheduler can show the operator what is wrong.
        if ($kind === 'startup') {
            $job->status = JobStatus::StartupFailed;
            $job->startupError = $this->childDiagnostic($job);
            $job->note = 'startup failure (exit ' . ExitCode::STARTUP . ')';
            $this->discard($job);
            return;
        }

        $capture = match ($kind) {
            'crash' => $this->options->capturesCrashes(),
            'sanitizer' => in_array('sanitizers', $this->options->capture, true),
            'leak' => $this->options->capturesLeaks(),
            'memory' => $this->options->capturesMemory(),
            'timeout' => $this->options->capturesTimeouts(),
            'failure' => $this->options->capturesFailures(),
            default => false,
        };
        if (!$capture) {
            $job->status = JobStatus::Skipped;
            $job->note = $this->describeVerdict($job, $verdict) . ' (not captured)';
            $this->discard($job);
            return;
        }

        $job->note = 'capturing…';
        $this->heartbeat?->__invoke();
        $trace = $this->finaliseTrace($job->traceDir, $job->workDir);

        // A 128+signal exit gives us a signal number even when the OS did not
        // report the child as "signaled"; keep it for the dashboard.
        $job->signal ??= $verdict->signal;

        // rr died of its own fault. Its SIGABRT looks exactly like a client
        // crash, but nothing here is about the client, so this is not a
        // failure and not a capture: discard it like any other uninteresting
        // run and let the scheduler count and log it as recorder noise. Filing
        // these would bury the real reproducers under one unusable trace per
        // aborted run. `--keep-work` still keeps the artifacts.
        if ($trace->rrFatal !== null) {
            $job->status = JobStatus::RrAborted;
            $job->rrFatal = $trace->rrFatal;
            $job->failure = false;
            $job->note = 'rr aborted while recording — ' . $trace->rrFatal;
            $this->discard($job);
            return;
        }

        // An unusable trace makes the whole capture untrustworthy: a recording
        // that never finalised cannot be replayed, and the run that produced it
        // may still have been a genuine failure. Park the evidence under
        // failed/ instead of filing a reproducer nobody can re-run.
        if ($trace->problem !== null) {
            $reason = (string) $trace->reason();
            $meta = $this->meta($job, $verdict);
            $job->reproDir = $this->store->captureFailed($job, $meta, $reason);
            $job->status = JobStatus::CaptureFailed;
            $job->note = $this->describeVerdict($job, $verdict) . ' — ' . $reason;
            $job->traceFailure = $reason;

            if (!$this->options->keepWork) {
                Fs::removeTree($job->workDir);
            }

            return;
        }

        $job->reproDir = $this->store->capture($job, $this->meta($job, $verdict));
        $job->status = match ($kind) {
            'sanitizer' => JobStatus::SanitizerFailed,
            'crash' => JobStatus::Crashed,
            'timeout' => JobStatus::TimedOut,
            'leak' => JobStatus::Leaked,
            'memory' => JobStatus::MemoryFailed,
            default => JobStatus::Failed,
        };
        $job->note = $this->describeVerdict($job, $verdict);

        // Logs and trace have been moved into the reproducer directory; only an
        // empty work directory is left behind.
        if (!$this->options->keepWork) {
            Fs::removeTree($job->workDir);
        }
    }

    /**
     * The message a child printed before giving up. stderr is where the
     * binaries write their errors; stdout is a fallback for a child that does
     * not. The tail is kept so a long log still ends with the failure.
     */
    private function childDiagnostic(Job $job): ?string
    {
        foreach (['stderr.log', 'stdout.log'] as $log) {
            $contents = @file_get_contents($job->workDir . '/' . $log);
            if (!is_string($contents) || trim($contents) === '') {
                continue;
            }

            return self::tail(trim($contents), 20, 4000);
        }

        return null;
    }

    /** The last $lines lines of $text, further truncated to $characters. */
    private static function tail(string $text, int $lines, int $characters): string
    {
        $split = preg_split('/\R/', $text);
        if ($split === false) {
            $split = [$text];
        }
        if (count($split) > $lines) {
            $split = array_slice($split, -$lines);
        }

        $tail = implode("\n", $split);

        return strlen($tail) > $characters
            ? '...' . substr($tail, -$characters)
            : $tail;
    }

    private function discard(Job $job): void
    {
        if ($this->options->keepWork) {
            return;
        }
        Fs::removeTree($job->workDir);
    }

    /**
     * How a run ended, for its `--run-log` header. Same labels the dashboard
     * uses, plus `ok` for the runs that had nothing wrong with them, so
     * `grep '^#' run.log | grep -v ':: ok'` lists every run that did not pass.
     */
    private function runLogLabel(Job $job, FailureClassifier $verdict): string
    {
        return $verdict->kind() === 'pass' ? 'ok' : $this->describeVerdict($job, $verdict);
    }

    /** A short human label for how a run ended, e.g. "SIGSEGV", "timeout", "leak 2 (2048 bytes)". */
    private function describeVerdict(Job $job, FailureClassifier $verdict): string
    {
        return match ($verdict->kind()) {
            'sanitizer' => 'sanitizer: ' . ($job->sanitizer->message ?? ''),
            'crash' => $verdict->signalName(),
            'memory' => 'memory',
            'timeout' => $job->killSignal === 9 ? 'timeout (SIGKILL)' : 'timeout',
            'leak' => $job->leak !== null
                ? sprintf('leak %d (%d bytes, %s)', $job->leak->count, $job->leak->bytes, $job->leak->source)
                : 'leak',
            default => 'exit ' . ($verdict->exitCode ?? '?'),
        };
    }

    /** @return array<string, mixed> */
    private function meta(Job $job, FailureClassifier $verdict): array
    {
        return [
            'run' => $job->id,
            'seed' => $job->seed,
            'port' => $job->port,
            'steps' => $job->steps,
            'signal' => $verdict->signal,
            'signal_name' => $verdict->signal !== null
                ? FailureClassifier::signalLabel($verdict->signal)
                : null,
            'exit_code' => $verdict->exitCode,
            'crashed' => $verdict->crashed,
            'timed_out' => $verdict->timedOut,
            'run_timeout_seconds' => $verdict->timedOut ? $this->options->runTimeout : null,
            'kill_signal' => $job->killSignal,
            'kill_signal_name' => $job->killSignal !== null
                ? FailureClassifier::signalLabel($job->killSignal)
                : null,
            'kind' => $verdict->kind(),
            'sanitizer_message' => $job->sanitizer?->message,
            'asan_environment' => $this->asan?->environment,
            'memory' => $verdict->memory,
            'memory_message' => $job->memory?->message,
            'leaked' => $verdict->leaked,
            'leak_count' => $job->leak?->count,
            'leak_bytes' => $job->leak?->bytes,
            'leak_site' => $job->leak?->firstSite,
            'leak_source' => $job->leak?->source,
            'duration_seconds' => round($job->duration(), 3),
            'rr' => $this->rrBinary !== null,
            'rr_chaos' => $this->options->rrChaos,
            'rr_trace' => $job->traceDir === null
                ? null
                : (RrTrace::isComplete($job->traceDir) ? 'complete' : 'incomplete'),
            'php' => $this->php,
            'php_args' => $job->phpArgs,
            'php_ini' => $job->phpIni,
            'php_ini_template' => $this->options->phpIni,
            'php_version' => $this->phpVersion,
            'command' => $job->argv,
            'captured_at' => date('c'),
        ];
    }

    /**
     * @param list<string> $phpArgs
     * @return list<string>
     */
    private function buildArgv(?int $port, int $steps, int $seed, int $slot, int $run, ?string $traceDir, array $phpArgs): array
    {
        $child = $this->template->render([
            'port' => $port ?? '',
            'steps' => $steps,
            'seed' => $seed,
            'job' => $slot,
            'run' => $run,
        ]);

        // Run the fuzzer script through the chosen PHP binary unless the caller
        // already put a php interpreter first.
        if (preg_match('/^php\d*(?:\.\d+)?$/', basename($child[0])) === 1) {
            $child = [$this->php, ...$phpArgs, ...array_slice($child, 1)];
        } else {
            $child = [$this->php, ...$phpArgs, ...$child];
        }

        // Pin the seed so a captured failure can be reproduced and reduced.
        if (!$this->template->uses('seed') && !$this->template->hasOption('seed')) {
            $child[] = '--seed=' . $seed;
        }

        if ($this->asan !== null) {
            $child = $this->asan->command($child);
        }

        if ($this->rrBinary !== null && $traceDir !== null) {
            $rr = [$this->rrBinary, 'record', '--output-trace-dir=' . $traceDir];
            if ($this->options->rrChaos) {
                $rr[] = '--chaos';
            }

            $child = [...$rr, ...$child];
        }

        // Children inherit the harness' process group, and therefore every
        // signal the terminal sends to it: SIGINT on Ctrl+C (which would kill
        // the runs the graceful shutdown is trying to let finish) and SIGWINCH
        // on every resize. A resize is the worse of the two — PHP ignores
        // SIGWINCH, but under rr it stops the tracee in the window where
        // `Task::spawn()` expects only SIGSTOP, and rr aborts with a fatal
        // error, producing an empty trace and a bogus SIGABRT "crash". Running
        // each child in its own session detaches it from the terminal so
        // neither signal is ever delivered. The children write to files and
        // read from /dev/null, so they have no use for a controlling terminal.
        if ($this->setsidBinary !== null) {
            $child = [$this->setsidBinary, ...$child];
        }

        return $child;
    }

    /**
     * @param list<string> $argv
     * @param list<string> $phpArgs
     * @return resource
     */
    private function open(array $argv, string $workDir, array $phpArgs)
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $workDir . '/stdout.log', 'w'],
            2 => ['file', $workDir . '/stderr.log', 'w'],
        ];

        // Run from the directory the harness was launched in so relative command
        // paths (bin/redis-client-tester-*) and relative core patterns resolve the way
        // the operator expects; per-run isolation of cores comes from the
        // mandated %p in core_pattern, not from a private working directory.
        $env = $this->childEnv();
        $env['REDIS_CLIENT_TESTER_WORK_DIR'] = $workDir;
        $env['REDIS_CLIENT_TESTER_PHP_ARGS'] = json_encode($phpArgs, JSON_THROW_ON_ERROR);
        // Defer handlers until ownership is recorded, without blocking signals in the child.
        $async = function_exists('pcntl_async_signals') ? pcntl_async_signals(false) : null;
        try {
            $process = proc_open($argv, $descriptors, $pipes, $this->baseDir, $env);
            if (!is_resource($process)) {
                throw new \RuntimeException('failed to launch: ' . implode(' ', $argv));
            }
            $this->processes[(int) $process] = [
                'process' => $process,
                'pid' => proc_get_status($process)['pid'],
                'directory' => $workDir,
            ];
        } finally {
            if ($async !== null) {
                pcntl_async_signals($async);
            }
        }

        return $process;
    }

    /** Stop every owned run, including a synchronous reduction interrupted mid-flight. */
    public function cleanup(): void
    {
        foreach ($this->processes as $child) {
            $this->kill($child, true);
        }
    }

    /** @param array{process: resource, pid: int, directory: string} $child */
    private function kill(array $child, bool $force): void
    {
        if ($this->setsidBinary !== null && function_exists('posix_kill')) {
            @posix_kill(-$child['pid'], 9);
        }
        @proc_terminate($child['process'], 9);
        Server::stopOrphans($child['directory'] . '/sapi', $force, $force ? null : $this->heartbeat);
    }

    /** @param resource $process */
    private function close($process): void
    {
        $id = (int) $process;
        $this->kill($this->processes[$id], false);
        @proc_close($process);
        unset($this->processes[$id]);
    }

    /** @return array<string, string> */
    private function childEnv(): array
    {
        $env = getenv();
        if (!isset($env['RUST_BACKTRACE'])) {
            // Relay is a Rust extension; a backtrace on panic is worth having.
            $env['RUST_BACKTRACE'] = '1';
        }

        return $env;
    }
}
