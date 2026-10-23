<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Viewer;

/**
 * One harness capture directory (`<output>/<kind>/<pid>.<nnnnn>/`), read-only.
 *
 * The headline is a best-effort one-line statement of the defect, chosen by how
 * the run was captured: the parsed sanitizer report, a glibc assertion message,
 * a gdb backtrace of a collected core (computed on demand), or the harness'
 * own memory/leak/timeout diagnostics.
 *
 * @phpstan-import-type Frame from Backtrace
 */
final class Reproducer
{
    public readonly ?SanitizerAnalysis $sanitizer;

    public ?Backtrace $coreBacktrace = null;

    public ?string $coreProblem = null;

    private ?string $assertion = null;

    /**
     * @param array<mixed> $meta
     * @param array<mixed>|null $minimized
     * @param list<string> $stderr
     */
    private function __construct(
        public readonly string $dir,
        public readonly string $id,
        public readonly array $meta,
        public readonly ?array $minimized,
        private readonly array $stderr,
    ) {
        $this->sanitizer = SanitizerAnalysis::parse($stderr);
        foreach ($stderr as $line) {
            // glibc: `php: src/relay.c:1171: relayFoo: Assertion `x != NULL' failed.`
            if (preg_match('/: ([^:\s]+:\d+): ([^:]+): Assertion `(.+)\' failed\.$/', $line, $m) === 1) {
                $this->assertion = "assertion `{$m[3]}` failed in {$m[2]} (" . Backtrace::shortLocation($m[1]) . ')';
                break;
            }
        }
    }

    public static function load(string $dir, string $root): ?self
    {
        $meta = self::json($dir . '/meta.json');
        if ($meta === null) {
            return null;
        }
        $stderr = @file($dir . '/stderr.log', FILE_IGNORE_NEW_LINES);
        $id = ltrim(substr($dir, strlen(rtrim($root, '/'))), '/');

        return new self($dir, $id !== '' ? $id : basename($dir), $meta, self::json($dir . '/minimized/meta.json'), $stderr === false ? [] : $stderr);
    }

    /** @return array<mixed>|null */
    private static function json(string $path): ?array
    {
        $raw = @file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function kind(): string
    {
        $kind = $this->meta['kind'] ?? null;

        return is_string($kind) ? $kind : basename(dirname($this->dir));
    }

    /** Captures parked under `failed/` (an rr trace that never finalised) cannot be replayed. */
    public function replayable(): bool
    {
        return !isset($this->meta['capture_failure']);
    }

    public function capturedAt(): string
    {
        return self::str($this->meta['captured_at'] ?? null) ?? '';
    }

    public function steps(): string
    {
        $steps = self::str($this->meta['steps'] ?? null) ?? '?';
        $min = self::str($this->minimized['steps'] ?? null);

        return $min !== null ? $min . '←' . $steps : $steps;
    }

    public function headline(): string
    {
        $text = match ($this->kind()) {
            'sanitizer' => $this->sanitizer?->headline() ?? self::str($this->meta['sanitizer_message'] ?? null),
            'crash' => $this->crashHeadline(),
            'memory' => self::str($this->meta['memory_message'] ?? null),
            'leak' => $this->leakHeadline(),
            'timeout' => sprintf(
                'timed out after %ss (%s)',
                self::str($this->meta['run_timeout_seconds'] ?? null) ?? '?',
                self::str($this->meta['kill_signal_name'] ?? null) ?? 'killed',
            ),
            default => sprintf('exit %s: %s', self::str($this->meta['exit_code'] ?? null) ?? '?', $this->lastLine() ?? ''),
        };
        // A sanitizer report beats the capture kind: under --asan a crash is reported, not signalled.
        if ($this->kind() !== 'sanitizer' && $this->sanitizer !== null) {
            $text = $this->sanitizer->headline();
        }

        return (string) preg_replace('/\s+/', ' ', trim((string) $text));
    }

    /** Groups captures of the same defect: addresses, pids, and seeds never take part. */
    public function signature(): string
    {
        if ($this->sanitizer !== null) {
            return $this->sanitizer->signature();
        }
        if ($this->assertion !== null) {
            return 'assert: ' . $this->assertion;
        }
        if ($this->coreBacktrace !== null) {
            return $this->signalName() . ' ' . $this->coreBacktrace->signature();
        }

        return $this->kind() . ': ' . preg_replace(['/0x[0-9a-f]+/', '/\d+/'], ['0x…', 'N'], $this->headline());
    }

    private function crashHeadline(): string
    {
        $text = $this->signalName();
        if ($this->assertion !== null) {
            return $text . ' ' . $this->assertion;
        }
        $site = $this->coreBacktrace?->site(2);
        if ($site !== null) {
            return $text . ' in ' . $site;
        }

        return $text . ($this->cores() !== [] ? ' (core collected: open it, or pass --backtraces)' : ' (no core collected)');
    }

    private function leakHeadline(): string
    {
        return sprintf(
            '%s leak: %s blocks, %s bytes%s',
            self::str($this->meta['leak_source'] ?? null) ?? 'memory',
            self::str($this->meta['leak_count'] ?? null) ?? '?',
            self::str($this->meta['leak_bytes'] ?? null) ?? '?',
            ($site = self::str($this->meta['leak_site'] ?? null)) !== null ? ' at ' . $site : '',
        );
    }

    public function signalName(): string
    {
        return self::str($this->meta['signal_name'] ?? null) ?? 'signal ' . (self::str($this->meta['signal'] ?? null) ?? '?');
    }

    /** @return list<string> */
    public function cores(): array
    {
        $cores = [];
        foreach ([$this->dir, $this->dir . '/minimized'] as $dir) {
            foreach (glob($dir . '/core*') ?: [] as $path) {
                if (is_file($path)) {
                    $cores[] = $path;
                }
            }
        }

        return $cores;
    }

    /**
     * Runs gdb on the first core once; the result is kept in memory only, so
     * the capture directory is never written to.
     */
    public function analyzeCore(?string $gdb): void
    {
        if ($this->coreBacktrace !== null || $this->coreProblem !== null) {
            return;
        }
        $core = $this->cores()[0] ?? null;
        $php = self::str($this->meta['php'] ?? null);
        if ($core === null || $php === null) {
            return;
        }
        if ($gdb === null) {
            $this->coreProblem = 'gdb not found on PATH';

            return;
        }
        $output = [];
        $status = 0;
        exec(
            'timeout 120 ' . escapeshellarg($gdb) . ' -batch -nx -q -ex "set pagination off" -ex "bt 40" '
            . escapeshellarg($php) . ' ' . escapeshellarg($core) . ' 2>/dev/null',
            $output,
            $status,
        );
        /** @var list<Frame> $frames */
        $frames = [];
        foreach ($output as $line) {
            // Loading a core prints the crashing frame once before `bt` lists them all.
            if (str_starts_with($line, '#0 ')) {
                $frames = [];
            }
            $frame = Backtrace::parseGdbFrame($line);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }
        if ($frames === []) {
            $this->coreProblem = "gdb produced no frames (exit {$status})";

            return;
        }
        $this->coreBacktrace = new Backtrace($frames);
    }

    /** @return list<array{string, list<string>}> Titled sections of plain text. */
    public function details(): array
    {
        $meta = fn (string $key): string => self::str($this->meta[$key] ?? null) ?? '-';
        $sections = [];

        $summary = [
            'headline:   ' . $this->headline(),
            'signature:  ' . $this->signature(),
            'kind:       ' . $this->kind() . ($this->replayable() ? '' : '  (NOT replayable: ' . $meta('capture_failure') . ')'),
            'directory:  ' . $this->dir,
            'captured:   ' . $this->capturedAt(),
            sprintf('run:        %s   seed %s   port %s   steps %s', $meta('run'), $meta('seed'), $meta('port'), $this->steps()),
            sprintf('outcome:    exit %s   signal %s   %ss', $meta('exit_code'), $meta('signal_name'), $meta('duration_seconds')),
            'php:        ' . $meta('php_version'),
        ];
        $sections[] = ['Summary', $summary];

        $replay = [];
        $command = @file($this->dir . '/command.txt', FILE_IGNORE_NEW_LINES);
        if ($command !== false && $command !== []) {
            $replay[] = $command[0];
            foreach ($command as $line) {
                if (str_starts_with($line, '# cwd:')) {
                    $replay[] = $line;
                }
            }
        }
        if ($this->minimized !== null) {
            $replay[] = sprintf('minimized: the same failure at %s steps (pass that as the step count); see minimized/', self::str($this->minimized['steps'] ?? null) ?? '?');
        }
        if (is_dir($this->dir . '/rr-trace')) {
            $replay[] = 'rr replay ' . $this->dir . '/rr-trace';
        }
        foreach ($this->cores() as $core) {
            $replay[] = 'gdb ' . $meta('php') . ' ' . $core;
        }
        $sections[] = ['Replay', $replay];

        if ($this->sanitizer !== null) {
            $analysis = [
                $this->sanitizer->tool . ': ' . $this->sanitizer->type . ($this->sanitizer->access !== null ? ' — ' . $this->sanitizer->access : ''),
            ];
            if ($this->sanitizer->reports > 1) {
                $analysis[] = $this->sanitizer->reports . ' reports in stderr.log (forked children); the first is analysed';
            }
            foreach (['stack' => 'access', 'freedBy' => 'freed by', 'allocatedBy' => 'allocated by'] as $property => $title) {
                $trace = $this->sanitizer->{$property};
                if ($trace->frames !== []) {
                    $analysis[] = '';
                    $analysis[] = $title . ':';
                    array_push($analysis, ...array_map(static fn (string $l): string => '  ' . $l, $trace->render(12)));
                }
            }
            $sections[] = ['Analysis', $analysis];
            $sections[] = ['Sanitizer report', $this->sanitizer->excerpt];
        } elseif ($this->kind() === 'crash') {
            $analysis = [$this->crashHeadline()];
            if ($this->coreBacktrace !== null) {
                $analysis[] = '';
                array_push($analysis, ...$this->coreBacktrace->render(30));
            } elseif ($this->coreProblem !== null) {
                $analysis[] = 'core backtrace unavailable: ' . $this->coreProblem;
            }
            $sections[] = ['Analysis', $analysis];
        }

        if ($this->sanitizer === null) {
            $sections[] = ['stderr.log (last 40 lines)', array_slice($this->stderr, -40)];
        }
        $stdout = @file($this->dir . '/stdout.log', FILE_IGNORE_NEW_LINES);
        $sections[] = ['stdout.log (last 15 lines)', $stdout === false ? [] : array_slice($stdout, -15)];

        $files = [];
        foreach (glob($this->dir . '/{,minimized/}*', GLOB_BRACE) ?: [] as $path) {
            $files[] = sprintf('%10s  %s', is_dir($path) ? 'dir' : number_format((int) filesize($path)), substr($path, strlen($this->dir) + 1));
        }
        $sections[] = ['Files', $files];

        return $sections;
    }

    private function lastLine(): ?string
    {
        for ($index = count($this->stderr) - 1; $index >= 0; $index--) {
            if (trim($this->stderr[$index]) !== '') {
                return $this->stderr[$index];
            }
        }

        return null;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * Every capture under $root, newest first. Accepts an output root
     * (`reproducers-asan`), a kind directory, or one capture directory.
     *
     * @return list<self>
     */
    public static function scan(string $root): array
    {
        $dirs = is_file($root . '/meta.json') ? [$root] : [];
        foreach (['/*/meta.json', '/*/*/meta.json', '/*/*/*/meta.json'] as $pattern) {
            foreach (glob(rtrim($root, '/') . $pattern) ?: [] as $meta) {
                $dir = dirname($meta);
                if (basename($dir) !== 'minimized' && !str_contains($dir, '/.work/')) {
                    $dirs[] = $dir;
                }
            }
        }
        $found = [];
        foreach (array_unique($dirs) as $dir) {
            $repro = self::load($dir, $root);
            if ($repro !== null) {
                $found[] = $repro;
            }
        }
        usort($found, static fn (self $a, self $b): int => [$b->capturedAt(), $b->id] <=> [$a->capturedAt(), $a->id]);

        return $found;
    }
}
