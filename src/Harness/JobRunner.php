<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Launches fuzzer child processes, polls them without blocking, classifies how
 * they ended, and hands captured failures to the {@see ReproStore}. Also
 * exposes a synchronous {@see rerun()} used by the {@see Reducer}.
 */
final class JobRunner
{
    private int $nextId = 1;

    public function __construct(
        private readonly HarnessOptions $options,
        private readonly string $php,
        private readonly string $baseDir,
        private readonly CommandTemplate $template,
        private readonly ReproStore $store,
        private readonly string $workRoot,
        private readonly ?string $rrBinary,
        private readonly string $phpVersion,
    ) {
        Fs::ensureDir($this->workRoot);
    }

    /** Number of runs launched so far (spawns and reduction re-runs). */
    public function launched(): int
    {
        return $this->nextId - 1;
    }

    public function spawn(int $slot, ?int $port, int $seed): Job
    {
        $id = $this->nextId++;
        $steps = $this->options->steps;
        $workDir = sprintf('%s/run-%05d', $this->workRoot, $id);
        Fs::ensureDir($workDir);

        $traceDir = $this->options->rr ? $workDir . '/rr-trace' : null;
        $argv = $this->buildArgv($port, $steps, $seed, $slot, $id, $traceDir);

        $process = $this->open($argv, $workDir);
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

        $status = $this->status($job);
        if ($status['running'] === true) {
            $expired = !$draining
                && $this->options->runTimeout > 0.0
                && $job->duration() > $this->options->runTimeout;

            if (!$draining && !$expired) {
                return;
            }

            $this->terminate($job);
            $job->timedOut = $expired;
            $status = $this->status($job);
        }

        $job->finishedAt = microtime(true);
        $job->signal = $status['signaled'] && $status['termsig'] > 0 ? $status['termsig'] : null;
        $job->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
        @proc_close($job->process);

        if ($draining) {
            $job->status = JobStatus::Skipped;
            $job->note = 'stopped';
            $this->discard($job);
            return;
        }

        if ($this->options->capturesLeaks()) {
            $job->leak = LeakReport::scan($job->workDir . '/stderr.log');
        }

        $verdict = FailureClassifier::fromExit(
            $status['signaled'],
            $job->signal,
            $job->exitCode,
            $job->timedOut,
            $job->leak !== null,
        );
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
        $argv = $this->buildArgv($port, $steps, $seed, 0, $id, $traceDir);

        $started = microtime(true);
        $process = $this->open($argv, $workDir);
        $deadline = $started + max(1.0, $timeout);

        $status = proc_get_status($process);
        while ($status['running'] === true) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                usleep(50_000);
                proc_get_status($process);
                @proc_close($process);

                return new JobOutcome(
                    FailureClassifier::fromExit(false, null, null, true),
                    $workDir,
                    null,
                    microtime(true) - $started,
                    null,
                );
            }
            usleep(20_000);
            $status = proc_get_status($process);
        }
        @proc_close($process);

        $signal = $status['signaled'] && $status['termsig'] > 0 ? $status['termsig'] : null;
        $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;

        if ($traceDir !== null) {
            RrTrace::awaitComplete($traceDir, $this->options->traceTimeout);
        }

        $leak = $this->options->capturesLeaks()
            ? LeakReport::scan($workDir . '/stderr.log')
            : null;

        return new JobOutcome(
            FailureClassifier::fromExit($status['signaled'], $signal, $exitCode, false, $leak !== null),
            $workDir,
            $traceDir,
            microtime(true) - $started,
            $status['pid'],
            $leak,
        );
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

    private function terminate(Job $job): void
    {
        proc_terminate($job->process, 15);
        if ($this->waitForExit($job, 3.0)) {
            return;
        }
        proc_terminate($job->process, 9);
        $this->waitForExit($job, 2.0);
    }

    private function waitForExit(Job $job, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($this->status($job)['running'] === false) {
                return true;
            }
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
        $capture = match ($kind) {
            'crash' => $this->options->capturesCrashes(),
            'leak' => $this->options->capturesLeaks(),
            'hang', 'failure' => $this->options->capturesFailures(),
            default => false,
        };
        if (!$capture) {
            $job->status = JobStatus::Skipped;
            $job->note = $this->describeVerdict($job, $verdict) . ' (not captured)';
            $this->discard($job);
            return;
        }

        if ($job->traceDir !== null) {
            RrTrace::awaitComplete($job->traceDir, $this->options->traceTimeout);
        }

        // A 128+signal exit gives us a signal number even when the OS did not
        // report the child as "signaled"; keep it for the dashboard.
        $job->signal ??= $verdict->signal;

        $job->reproDir = $this->store->capture($job, $verdict, $this->meta($job, $verdict));
        $job->status = match ($kind) {
            'crash' => JobStatus::Crashed,
            'hang' => JobStatus::TimedOut,
            'leak' => JobStatus::Leaked,
            default => JobStatus::Failed,
        };
        $job->note = $this->describeVerdict($job, $verdict);

        // Logs and trace have been moved into the reproducer directory; only an
        // empty work directory is left behind.
        if (!$this->options->keepWork) {
            Fs::removeTree($job->workDir);
        }
    }

    private function discard(Job $job): void
    {
        if ($this->options->keepWork) {
            return;
        }
        Fs::removeTree($job->workDir);
    }

    /** A short human label for how a run ended, e.g. "SIGSEGV", "hang", "leak 2 (2048 bytes)". */
    private function describeVerdict(Job $job, FailureClassifier $verdict): string
    {
        return match ($verdict->kind()) {
            'crash' => $verdict->signalName(),
            'hang' => 'hang',
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
            'leaked' => $verdict->leaked,
            'leak_count' => $job->leak?->count,
            'leak_bytes' => $job->leak?->bytes,
            'leak_site' => $job->leak?->firstSite,
            'leak_source' => $job->leak?->source,
            'duration_seconds' => round($job->duration(), 3),
            'rr' => $this->rrBinary !== null,
            'rr_chaos' => $this->options->rrChaos,
            'php' => $this->php,
            'php_version' => $this->phpVersion,
            'command' => $job->argv,
            'captured_at' => date('c'),
        ];
    }

    /**
     * @return list<string>
     */
    private function buildArgv(?int $port, int $steps, int $seed, int $slot, int $run, ?string $traceDir): array
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
            $child = [$this->php, ...array_slice($child, 1)];
        } else {
            $child = [$this->php, ...$child];
        }

        // Pin the seed so a captured failure can be reproduced and reduced.
        if (!$this->template->uses('seed') && !$this->template->hasOption('seed')) {
            $child[] = '--seed=' . $seed;
        }

        if ($this->rrBinary !== null && $traceDir !== null) {
            $rr = [$this->rrBinary, 'record', '--output-trace-dir=' . $traceDir];
            if ($this->options->rrChaos) {
                $rr[] = '--chaos';
            }

            return [...$rr, ...$child];
        }

        return $child;
    }

    /**
     * @param list<string> $argv
     * @return resource
     */
    private function open(array $argv, string $workDir)
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $workDir . '/stdout.log', 'w'],
            2 => ['file', $workDir . '/stderr.log', 'w'],
        ];

        // Run from the directory the harness was launched in so relative command
        // paths (bin/phpredis-fuzz-*) and relative core patterns resolve the way
        // the operator expects; per-run isolation of cores comes from the
        // mandated %p in core_pattern, not from a private working directory.
        $process = proc_open($argv, $descriptors, $pipes, $this->baseDir, $this->childEnv());
        if (!is_resource($process)) {
            throw new \RuntimeException('failed to launch: ' . implode(' ', $argv));
        }

        return $process;
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
