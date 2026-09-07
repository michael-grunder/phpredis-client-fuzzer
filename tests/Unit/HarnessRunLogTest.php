<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Harness\Job;
use Mgrunder\PhpredisCommandFuzzer\Harness\RunLog;
use PHPUnit\Framework\TestCase;

final class HarnessRunLogTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            Fs::removeTree($this->root);
        }
    }

    public function testEachRunContributesAHeaderAndItsOwnOutput(): void
    {
        $log = new RunLog($this->workspace() . '/runs.log', 4242);

        $log->append($this->job(7, 99, "{\n  \"seed\": 99\n}\n"), 'ok');
        $log->append($this->job(8, 100, "{\n  \"seed\": 100\n}\n"), 'SIGSEGV');

        $contents = $this->contents($log);
        self::assertStringContainsString(
            'harness=4242 run=7 seed=99 port=7000 steps=5000',
            $contents,
        );
        self::assertStringContainsString(':: ok', $contents);
        self::assertStringContainsString(':: SIGSEGV', $contents);
        self::assertStringContainsString('"seed": 99', $contents);
        self::assertStringContainsString('"seed": 100', $contents);
    }

    public function testHeadersAreTheOnlyLinesAddedToTheRunsOwnOutput(): void
    {
        $log = new RunLog($this->workspace() . '/runs.log', 1);

        $first = "{\n  \"seed\": 1\n}\n";
        $second = "{\n  \"seed\": 2\n}\n";
        $log->append($this->job(1, 1, $first), 'ok');
        $log->append($this->job(2, 2, $second), 'exit 1');

        $headers = [];
        $body = '';
        foreach ($this->lines($log) as $line) {
            if (str_starts_with($line, '#')) {
                $headers[] = $line;
                continue;
            }
            $body .= $line . "\n";
        }

        // Stripping the headers has to leave exactly what the runs printed, so
        // `grep -v '^#' run.log | jq` sees an unbroken stream of their
        // documents and nothing of the harness'.
        self::assertCount(2, $headers);
        self::assertSame($first . $second, $body);
    }

    public function testARunThatPrintedNothingIsStillRecorded(): void
    {
        $log = new RunLog($this->workspace() . '/runs.log', 1);

        $log->append($this->job(3, 30, ''), 'SIGKILL');

        $lines = $this->lines($log);
        self::assertCount(2, $lines);
        self::assertStringContainsString('run=3 seed=30', $lines[0]);
        self::assertSame('# (no output)', $lines[1]);
    }

    public function testAnUnterminatedLastLineDoesNotSwallowTheNextHeader(): void
    {
        $log = new RunLog($this->workspace() . '/runs.log', 1);

        // A run killed mid-write.
        $log->append($this->job(1, 1, '{"seed": 1, "ste'), 'timeout (SIGKILL)');
        $log->append($this->job(2, 2, "done\n"), 'ok');

        $lines = $this->lines($log);
        self::assertSame('{"seed": 1, "ste', $lines[1]);
        self::assertStringStartsWith('# ', $lines[2]);
        self::assertStringContainsString('run=2 seed=2', $lines[2]);
    }

    public function testAMissingWorkDirectoryIsNotAnError(): void
    {
        $log = new RunLog($this->workspace() . '/runs.log', 1);

        $job = $this->job(1, 1, '');
        Fs::removeTree($job->workDir);
        $log->append($job, 'ok');

        self::assertCount(2, $this->lines($log));
    }

    public function testAPortlessRunOmitsThePortField(): void
    {
        $log = new RunLog($this->workspace() . '/runs.log', 1);

        $log->append($this->job(1, 1, "x\n", null), 'ok');

        self::assertStringNotContainsString(' port=', $this->lines($log)[0]);
    }

    public function testOpeningAnExistingLogAppendsRatherThanTruncatingIt(): void
    {
        $path = $this->workspace() . '/runs.log';
        file_put_contents($path, "# an earlier campaign\n");

        $log = RunLog::open($path, 7);
        $log->append($this->job(1, 1, "later\n"), 'ok');

        self::assertStringContainsString('# an earlier campaign', $this->contents($log));
        self::assertStringContainsString('later', $this->contents($log));
    }

    public function testAnUnwritablePathIsRejectedBeforeAnyRunIsSpawned(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot open --run-log for appending');

        RunLog::open($this->workspace() . '/no/such/directory/runs.log', 1);
    }

    private function contents(RunLog $log): string
    {
        return (string) file_get_contents($log->path());
    }

    /** @return list<string> */
    private function lines(RunLog $log): array
    {
        $lines = explode("\n", $this->contents($log));
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** A finished job whose work directory holds $stdout. */
    private function job(int $id, int $seed, string $stdout, ?int $port = 7000): Job
    {
        $workDir = $this->root . '/work/run-' . $id;
        Fs::ensureDir($workDir);
        file_put_contents($workDir . '/stdout.log', $stdout);

        $process = proc_open([PHP_BINARY, '-r', 'exit(0);'], [], $pipes);
        self::assertIsResource($process);

        $job = new Job(
            id: $id,
            slot: 0,
            port: $port,
            steps: 5000,
            seed: $seed,
            workDir: $workDir,
            traceDir: null,
            argv: ['php'],
            startedAt: microtime(true),
            process: $process,
        );
        $job->pid = 1234;
        $job->finishedAt = $job->startedAt + 1.25;
        @proc_close($process);

        return $job;
    }

    private function workspace(): string
    {
        $this->root = sys_get_temp_dir() . '/phpredis-fuzz-runlog-' . getmypid() . '-' . mt_rand();
        Fs::ensureDir($this->root);

        return $this->root;
    }
}
