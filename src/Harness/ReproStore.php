<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Persists a captured failure: its logs, the rr trace, any core dumps, the
 * exact command line, and a machine-readable `meta.json`. Nothing here is ever
 * deleted; these directories may be the only evidence of a client defect.
 */
final class ReproStore
{
    public function __construct(
        private readonly string $root,
        private readonly CorePattern $core,
        private readonly string $coreSearchDir,
    ) {
        Fs::ensureDir($this->root);
    }

    /**
     * @param array<string, mixed> $meta
     * @return string The created reproducer directory.
     */
    public function capture(Job $job, FailureClassifier $verdict, array $meta): string
    {
        $label = match ($verdict->kind()) {
            'crash' => strtolower($verdict->signalName()),
            'hang' => 'timeout',
            'leak' => 'leak',
            default => 'exit' . ($verdict->exitCode ?? 0),
        };

        $dir = sprintf(
            '%s/repro-%s-%s-seed%d-run%d',
            $this->root,
            date('Ymd-His'),
            $label,
            $job->seed,
            $job->id,
        );
        Fs::ensureDir($dir);

        foreach (['stdout.log', 'stderr.log'] as $log) {
            Fs::move($job->workDir . '/' . $log, $dir . '/' . $log);
        }
        if ($job->traceDir !== null && is_dir($job->traceDir)) {
            Fs::move($job->traceDir, $dir . '/rr-trace');
        }
        if ($job->pid !== null) {
            $this->collectCores($job->pid, $dir);
        }

        file_put_contents($dir . '/command.txt', $this->commandText($job));
        file_put_contents(
            $dir . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $dir;
    }

    /**
     * Store the artifacts of a minimised re-run next to the original capture.
     */
    public function addMinimized(string $dir, JobOutcome $outcome, int $steps): void
    {
        $sub = $dir . '/minimized';
        Fs::ensureDir($sub);

        foreach (['stdout.log', 'stderr.log'] as $log) {
            Fs::move($outcome->workDir . '/' . $log, $sub . '/' . $log);
        }
        if ($outcome->traceDir !== null && is_dir($outcome->traceDir)) {
            Fs::move($outcome->traceDir, $sub . '/rr-trace');
        }
        if ($outcome->pid !== null) {
            $this->collectCores($outcome->pid, $sub);
        }

        file_put_contents($sub . '/meta.json', json_encode([
            'steps' => $steps,
            'signal' => $outcome->verdict->signal,
            'signal_name' => $outcome->verdict->signal !== null
                ? FailureClassifier::signalLabel($outcome->verdict->signal)
                : null,
            'exit_code' => $outcome->verdict->exitCode,
            'crashed' => $outcome->verdict->crashed,
            'leaked' => $outcome->verdict->leaked,
            'duration_seconds' => round($outcome->duration, 3),
            'captured_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function collectCores(int $pid, string $destination): int
    {
        $found = 0;
        foreach ($this->core->globsForPid($pid, $this->coreSearchDir) as $pattern) {
            $matches = glob($pattern);
            if ($matches === false) {
                continue;
            }
            foreach ($matches as $path) {
                if (!is_file($path)) {
                    continue;
                }
                if (Fs::move($path, $destination . '/' . basename($path))) {
                    $found++;
                }
            }
        }

        return $found;
    }

    private function commandText(Job $job): string
    {
        $parts = array_map(
            static fn (string $argument): string => preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $argument) === 1
                ? $argument
                : escapeshellarg($argument),
            $job->argv,
        );

        $lines = [
            implode(' ', $parts),
            '',
            "# cwd:   {$this->coreSearchDir}",
            "# seed:  {$job->seed}",
        ];
        if ($job->port !== null) {
            $lines[] = "# port:  {$job->port}";
        }
        $lines[] = "# steps: {$job->steps}";

        return implode("\n", $lines) . "\n";
    }
}
