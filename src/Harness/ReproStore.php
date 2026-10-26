<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Harness;

/**
 * Persists a captured failure: its logs, the rr trace, any core dumps, the
 * exact command line, and a machine-readable `meta.json`. Nothing here is ever
 * deleted; these directories may be the only evidence of a client defect.
 *
 * Captures are stored under `<kind>/<harness pid>.<sequence>`, so several
 * harnesses can share one output directory. Within each kind, a campaign's
 * reproducers stay grouped and ordered by age. Pids are recycled, so the
 * sequence is not assumed to start at one:
 * {@see allocate()} claims the first free number instead.
 *
 * A failure whose artifacts cannot be replayed — an rr trace that never lost
 * its `incomplete` sentinel — is kept just as carefully, but under the
 * `failed/` subdirectory, separate from the replayable captures in the
 * failure-kind subdirectories.
 */
final class ReproStore
{
    /**
     * The point at which a shared output directory has stopped being useful.
     * Only a guard against spinning forever on a directory we cannot write to.
     */
    private const MAX_SEQUENCE = 999999;

    /** The subdirectory holding failures whose artifacts are not replayable. */
    public const FAILED = 'failed';

    private readonly int $pid;

    /**
     * Where the next allocation starts looking, per destination directory;
     * never revisits a taken name.
     *
     * @var array<string, int>
     */
    private array $next = [];

    public function __construct(
        private readonly string $root,
        private readonly CorePattern $core,
        private readonly string $coreSearchDir,
        private readonly ?string $phpIni = null,
        ?int $pid = null,
    ) {
        $resolved = $pid ?? getmypid();
        $this->pid = $resolved === false ? 0 : $resolved;

        Fs::ensureDir($this->root);
    }

    /** The pid the capture directories of this campaign are named after. */
    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * @param array<string, mixed> $meta
     * @return string The created reproducer directory.
     */
    public function capture(Job $job, array $meta): string
    {
        $kind = match ($meta['kind'] ?? null) {
            'crash' => 'crash',
            'sanitizer' => 'sanitizer',
            'memory' => 'memory',
            'leak' => 'leak',
            'timeout' => 'timeout',
            default => 'failure',
        };

        return $this->store($job, $meta, $this->root . '/' . $kind);
    }

    /**
     * Park a failure whose artifacts cannot be replayed under `failed/`, with a
     * `FAILED.txt` saying why.
     *
     * The evidence is still worth keeping — it is how an operator sees that rr
     * has been dying on them — it just must not sit where the reproducers that
     * can actually be re-run live.
     *
     * @param array<string, mixed> $meta
     * @return string The created directory.
     */
    public function captureFailed(Job $job, array $meta, string $reason): string
    {
        $meta['capture_failure'] = $reason;
        $dir = $this->store($job, $meta, $this->root . '/' . self::FAILED);

        file_put_contents(
            $dir . '/FAILED.txt',
            $reason . "\n\nThis run is not replayable and was not counted as a reproducer.\n",
        );

        return $dir;
    }

    /**
     * @param array<string, mixed> $meta
     * @return string The created directory.
     */
    private function store(Job $job, array $meta, string $root): string
    {
        $dir = $this->allocate($root);

        foreach (['stdout.log', 'stderr.log'] as $log) {
            Fs::move($job->workDir . '/' . $log, $dir . '/' . $log);
        }
        if ($job->traceDir !== null && is_dir($job->traceDir)) {
            Fs::move($job->traceDir, $dir . '/rr-trace');
        }
        if ($job->pid !== null) {
            $this->collectCores($job->pid, $dir);
        }
        $this->collectSapi($job->workDir, $dir);

        $ini = $job->phpIni ?? $this->phpIni;
        $this->copyIni($dir, $ini);
        $meta['php_ini_capture'] = $ini === null ? null : 'php.ini';

        file_put_contents($dir . '/command.txt', $this->commandText($job, $dir, $ini));
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
        $traceProblem = RrTrace::problem($outcome->traceDir);
        if ($outcome->traceDir !== null && $traceProblem === null) {
            Fs::move($outcome->traceDir, $sub . '/rr-trace');
        }
        if ($outcome->pid !== null) {
            $this->collectCores($outcome->pid, $sub);
        }
        $this->collectSapi($outcome->workDir, $sub);
        $generatedIni = $outcome->workDir . '/php.ini';
        $this->copyIni($sub, is_file($generatedIni) ? $generatedIni : $this->phpIni);

        file_put_contents($sub . '/meta.json', json_encode([
            'steps' => $steps,
            'trace_failure' => $traceProblem,
            'signal' => $outcome->verdict->signal,
            'signal_name' => $outcome->verdict->signal !== null
                ? FailureClassifier::signalLabel($outcome->verdict->signal)
                : null,
            'exit_code' => $outcome->verdict->exitCode,
            'crashed' => $outcome->verdict->crashed,
            'timed_out' => $outcome->verdict->timedOut,
            'kind' => $outcome->verdict->kind(),
            'sanitizer_message' => $outcome->sanitizer?->message,
            'memory' => $outcome->verdict->memory,
            'memory_message' => $outcome->memory?->message,
            'leaked' => $outcome->verdict->leaked,
            'leak_source' => $outcome->leak?->source,
            'duration_seconds' => round($outcome->duration, 3),
            'captured_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Keep the ini a campaign ran with next to the failure it produced. The
     * file on disk can be edited or deleted long before anyone comes back to
     * the reproducer, and without it the recorded `-c <path>` may no longer
     * describe the run that crashed.
     */
    private function copyIni(string $dir, ?string $ini): void
    {
        if ($ini === null) {
            return;
        }
        if (!@copy($ini, $dir . '/php.ini')) {
            throw new \RuntimeException("cannot preserve php ini file: {$ini}");
        }
    }

    /**
     * Claim the next free `<pid>.<sequence>` directory.
     *
     * `mkdir()` without the recursive flag is the atomic primitive here: on
     * Linux it fails with EEXIST rather than reusing a name, so a directory
     * left behind by an older harness that happened to hold this pid, or one
     * being claimed right now by a concurrent harness, can never be written
     * into twice. That makes "find the first unused number" safe without a
     * lock file or a stat-then-create race.
     */
    private function allocate(string $root): string
    {
        Fs::ensureDir($root);

        for ($sequence = $this->next[$root] ?? 1; $sequence <= self::MAX_SEQUENCE; $sequence++) {
            $dir = sprintf('%s/%d.%05d', $root, $this->pid, $sequence);
            if (@mkdir($dir, 0o777)) {
                $this->next[$root] = $sequence + 1;

                return $dir;
            }
            if (!is_dir($dir)) {
                throw new \RuntimeException("cannot create reproducer directory: {$dir}");
            }
        }

        throw new \RuntimeException(
            sprintf('reproducer directory %s already holds %d captures for pid %d; use a fresh --output', $root, self::MAX_SEQUENCE, $this->pid),
        );
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

    private function collectSapi(string $workDir, string $destination): void
    {
        $sapi = $workDir . '/sapi';
        if (!is_dir($sapi)) {
            return;
        }
        $pids = @file($sapi . '/pids.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($pids === false ? [] : $pids as $pid) {
            if (ctype_digit($pid) && (int) $pid > 0) {
                $this->collectCores((int) $pid, $destination);
            }
        }
        if (!Fs::move($sapi, $destination . '/sapi')) {
            throw new \RuntimeException('cannot preserve SAPI artifacts: ' . $sapi);
        }
    }

    /**
     * A copy-pasteable rendering of a child's argv, quoting only the arguments
     * that need it.
     *
     * @param list<string> $argv
     */
    public static function formatCommand(array $argv): string
    {
        $parts = array_map(
            static fn (string $argument): string => preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $argument) === 1
                ? $argument
                : escapeshellarg($argument),
            $argv,
        );

        return implode(' ', $parts);
    }

    private function commandText(Job $job, string $dir, ?string $ini): string
    {
        $argv = $job->argv;
        if ($ini !== null) {
            foreach ($argv as $index => $arg) {
                if ($arg === $ini && ($argv[$index - 1] ?? null) === '-c') {
                    $argv[$index] = $dir . '/php.ini';
                }
            }
        }
        $command = self::formatCommand($argv);
        if (is_dir($dir . '/sapi')) {
            $args = array_map(static fn (string $arg): string => $ini !== null && $arg === $ini ? $dir . '/php.ini' : $arg, $job->phpArgs);
            $command = 'REDIS_CLIENT_TESTER_PHP_ARGS=' . escapeshellarg(json_encode($args, JSON_THROW_ON_ERROR)) . ' ' . $command;
        }
        $lines = [
            $command,
            '',
            "# cwd:   {$this->coreSearchDir}",
            "# seed:  {$job->seed}",
        ];
        if ($ini !== null) {
            $lines[] = "# ini:   {$ini} (copied to php.ini)";
        }
        if ($job->port !== null) {
            $lines[] = "# port:  {$job->port}";
        }
        $lines[] = "# steps: {$job->steps}";

        return implode("\n", $lines) . "\n";
    }
}
