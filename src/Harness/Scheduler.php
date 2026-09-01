<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * The campaign loop: keeps `--jobs` fuzzer runs in flight, reaps and classifies
 * each one, captures and (optionally) minimises failures, and stops on the
 * configured limits or an operator quit.
 */
final class Scheduler
{
    /** @var list<Job> */
    private array $active = [];

    /** @var list<Job> newest first */
    private array $recent = [];

    /** @var array<int, true> occupied display slots */
    private array $slots = [];

    private bool $stopping = false;

    private ?int $seedCursor;

    public function __construct(
        private readonly HarnessOptions $options,
        private readonly string $outputDir,
        private readonly JobRunner $runner,
        private readonly Reducer $reducer,
        private readonly ReproStore $store,
        private readonly PortPool $ports,
        private readonly Stats $stats,
        private readonly View $view,
        private readonly string $phpVersion,
    ) {
        $this->seedCursor = $options->seed;
    }

    public function run(): int
    {
        $this->installSignalHandlers();
        $this->view->start();

        try {
            while (true) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                if ($this->view->quitRequested()) {
                    $this->stopping = true;
                }

                $this->reap();
                if (!$this->stopping) {
                    $this->fill();
                }
                $this->view->render($this->stats, $this->snapshot());

                if ($this->stopping && $this->active === []) {
                    break;
                }
                if (!$this->stopping && $this->campaignComplete()) {
                    $this->stopping = true;
                }

                usleep(50_000);
            }

            $this->drain();
            $this->view->render($this->stats, $this->snapshot());
        } finally {
            $this->view->stop();
        }

        $this->summary();

        return $this->stats->reproducers > 0 ? 1 : 0;
    }

    private function reap(): void
    {
        $survivors = [];
        foreach ($this->active as $job) {
            $this->runner->poll($job);
            if ($job->isRunning()) {
                $survivors[] = $job;
                continue;
            }

            $this->stats->completed++;
            if ($job->failure) {
                $this->stats->failures++;
            }
            match ($job->status) {
                JobStatus::Passed => $this->stats->passed++,
                JobStatus::Skipped => $this->stats->skipped++,
                JobStatus::Failed, JobStatus::Crashed, JobStatus::TimedOut => $this->onCapture($job),
                JobStatus::Running => null,
            };

            if ($job->port !== null) {
                $this->ports->release($job->port);
            }
            unset($this->slots[$job->slot]);
            $this->pushRecent($job);
            $this->view->note($this->describe($job));
        }

        $this->active = $survivors;
    }

    private function onCapture(Job $job): void
    {
        if ($job->status === JobStatus::Crashed) {
            $this->stats->crashes++;
        }
        if ($job->status === JobStatus::TimedOut) {
            $this->stats->timeouts++;
        }
        $this->stats->reproducers++;

        if ($this->options->reduce !== 'steps' || $job->reproDir === null || $job->steps <= 1) {
            return;
        }

        $original = $job->note;
        $job->note = 'reducing…';
        $this->view->render($this->stats, $this->snapshot());

        $result = $this->reducer->reduce($job);
        if ($result === null) {
            $job->note = $original !== '' ? $original : 'not reducible';
            return;
        }

        $job->reducedSteps = $result['steps'];
        $finalTimeout = max(10.0, $job->duration() * 4.0);
        $final = $this->runner->rerun(
            $job->seed,
            $job->port,
            $result['steps'],
            $finalTimeout,
            $this->options->rr,
        );
        $this->store->addMinimized($job->reproDir, $final, $result['steps']);
        $this->runner->cleanupOutcome($final);
        $this->runner->cleanupOutcome($result['outcome']);
        $this->stats->reductions++;
        $job->note = sprintf('%s min %d/%d', $original !== '' ? $original : 'repro', $result['steps'], $job->steps);
    }

    private function fill(): void
    {
        while (count($this->active) < $this->options->jobs && $this->canStart()) {
            $port = null;
            if (!$this->ports->isEmpty()) {
                $port = $this->ports->acquire();
                if ($port === null) {
                    return; // every isolated port is busy right now
                }
            }

            $slot = $this->takeSlot();
            $seed = $this->nextSeed();

            try {
                $job = $this->runner->spawn($slot, $port, $seed);
            } catch (\Throwable $exception) {
                if ($port !== null) {
                    $this->ports->release($port);
                }
                unset($this->slots[$slot]);
                $this->view->note('spawn failed: ' . $exception->getMessage());
                $this->stopping = true;
                return;
            }

            $this->active[] = $job;
            $this->stats->started++;
        }
    }

    private function canStart(): bool
    {
        if ($this->stopping) {
            return false;
        }
        if ($this->options->maxRuns > 0 && $this->stats->started >= $this->options->maxRuns) {
            return false;
        }
        if ($this->options->maxSeconds > 0.0 && $this->stats->elapsed() >= $this->options->maxSeconds) {
            return false;
        }
        if ($this->options->maxReproducers > 0 && $this->stats->reproducers >= $this->options->maxReproducers) {
            return false;
        }

        return true;
    }

    private function campaignComplete(): bool
    {
        if ($this->options->maxReproducers > 0 && $this->stats->reproducers >= $this->options->maxReproducers) {
            return true;
        }
        if ($this->active !== [] || $this->canStart()) {
            return false;
        }

        // Nothing running and canStart() is false: a limit has been reached.
        return $this->options->maxRuns > 0 || $this->options->maxSeconds > 0.0;
    }

    private function drain(): void
    {
        if ($this->active === []) {
            return;
        }

        $this->view->note('stopping — terminating ' . count($this->active) . ' run(s)…');
        foreach ($this->active as $job) {
            $this->runner->poll($job, true);
            if ($job->port !== null) {
                $this->ports->release($job->port);
            }
            unset($this->slots[$job->slot]);
            $this->pushRecent($job);
        }
        $this->active = [];
    }

    private function takeSlot(): int
    {
        for ($slot = 0; $slot < $this->options->jobs; $slot++) {
            if (!isset($this->slots[$slot])) {
                $this->slots[$slot] = true;
                return $slot;
            }
        }

        $this->slots[$this->options->jobs] = true;
        return $this->options->jobs;
    }

    private function nextSeed(): int
    {
        if ($this->seedCursor === null) {
            return random_int(0, PHP_INT_MAX);
        }

        return $this->seedCursor++;
    }

    private function pushRecent(Job $job): void
    {
        array_unshift($this->recent, $job);
        $this->recent = array_slice($this->recent, 0, 40);
    }

    private function snapshot(): DashboardState
    {
        return new DashboardState(
            jobs: $this->options->jobs,
            steps: $this->options->steps,
            ports: $this->ports->all(),
            rr: $this->options->rr,
            rrChaos: $this->options->rrChaos,
            onlyCrashes: $this->options->onlyCrashes,
            reduce: $this->options->reduce,
            output: $this->outputDir,
            phpVersion: $this->phpVersion,
            active: $this->active,
            recent: array_slice($this->recent, 0, 15),
            maxRuns: $this->options->maxRuns > 0 ? $this->options->maxRuns : null,
            maxReproducers: $this->options->maxReproducers > 0 ? $this->options->maxReproducers : null,
            maxSeconds: $this->options->maxSeconds > 0.0 ? $this->options->maxSeconds : null,
        );
    }

    private function describe(Job $job): string
    {
        $head = sprintf(
            'run %d (seed %d%s, %.1fs) ',
            $job->id,
            $job->seed,
            $job->port !== null ? ', port ' . $job->port : '',
            $job->duration(),
        );
        $repro = $job->reproDir !== null ? ' -> ' . basename($job->reproDir) : '';
        $min = $job->reducedSteps !== null ? sprintf(' (min %d steps)', $job->reducedSteps) : '';

        return match ($job->status) {
            JobStatus::Passed => $head . 'ok',
            JobStatus::Skipped => $head . ($job->note !== '' ? $job->note : 'skipped'),
            JobStatus::Crashed => $head . 'CRASH ' . FailureClassifier::signalLabel($job->signal) . $repro . $min,
            JobStatus::Failed => $head . 'FAIL exit ' . ($job->exitCode ?? '?') . $repro . $min,
            JobStatus::TimedOut => $head . 'HANG' . $repro . $min,
            JobStatus::Running => $head . 'running',
        };
    }

    private function summary(): void
    {
        $lines = [
            '',
            sprintf(
                'harness: %d runs, %d failures, %d crashes, %d hangs, %d reproducers, %d reduced in %s',
                $this->stats->completed,
                $this->stats->failures,
                $this->stats->crashes,
                $this->stats->timeouts,
                $this->stats->reproducers,
                $this->stats->reductions,
                $this->stats->formatElapsed(),
            ),
        ];
        if ($this->stats->reproducers > 0) {
            $lines[] = 'harness: reproducers saved under ' . $this->outputDir;
        }

        fwrite(STDOUT, implode("\n", $lines) . "\n");
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stopping = true;
        };

        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $handler);
        }
        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handler);
        }
    }
}
