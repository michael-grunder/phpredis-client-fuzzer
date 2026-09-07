<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Harness\Job;
use Mgrunder\PhpredisCommandFuzzer\Harness\RrAbortLog;
use PHPUnit\Framework\TestCase;

final class HarnessRrAbortLogTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            Fs::removeTree($this->root);
        }
    }

    public function testEachAbortAppendsOneLineNamingTheRunAndTheHarness(): void
    {
        $log = RrAbortLog::inDirectory($this->workspace());

        $log->append(4242, $this->job(7, 99), 'rr: Unable to create version file /w/run-1/rr-trace/version');
        $log->append(4242, $this->job(8, 100), 'rr: died with a backtrace of its own');

        $lines = $this->lines($log);
        self::assertCount(2, $lines);
        self::assertStringContainsString('harness=4242 run=7 seed=99 port=7000', $lines[0]);
        self::assertStringContainsString('Unable to create version file', $lines[0]);
        self::assertStringContainsString('run=8 seed=100', $lines[1]);
    }

    public function testTheRetainedWorkDirectoryIsRecordedOnlyWhenThereIsOne(): void
    {
        $log = RrAbortLog::inDirectory($this->workspace());

        $log->append(1, $this->job(1, 1), 'rr: boom');
        $log->append(1, $this->job(2, 2), 'rr: boom', '/tmp/work/run-00002');

        $lines = $this->lines($log);
        self::assertStringNotContainsString(' work=', $lines[0]);
        self::assertStringContainsString(' work=/tmp/work/run-00002', $lines[1]);
    }

    public function testAMultiLineDiagnosticStaysOnOneLine(): void
    {
        $log = RrAbortLog::inDirectory($this->workspace());

        $log->append(1, $this->job(1, 1), "rr: assertion failed\n=== Start rr backtrace:\n  frame\n");

        self::assertCount(1, $this->lines($log));
        self::assertStringContainsString('rr: assertion failed === Start rr backtrace: frame', $this->lines($log)[0]);
    }

    /** @return list<string> */
    private function lines(RrAbortLog $log): array
    {
        $contents = (string) file_get_contents($log->path());

        return array_values(array_filter(explode("\n", $contents), static fn (string $l): bool => $l !== ''));
    }

    private function job(int $id, int $seed): Job
    {
        $process = proc_open([PHP_BINARY, '-r', 'exit(0);'], [], $pipes);
        self::assertIsResource($process);

        $job = new Job(
            id: $id,
            slot: 0,
            port: 7000,
            steps: 1,
            seed: $seed,
            workDir: '/tmp/work/run-' . $id,
            traceDir: null,
            argv: ['php'],
            startedAt: microtime(true),
            process: $process,
        );
        $job->finishedAt = $job->startedAt + 1.25;
        @proc_close($process);

        return $job;
    }

    private function workspace(): string
    {
        $this->root = sys_get_temp_dir() . '/phpredis-fuzz-rrabort-' . getmypid() . '-' . mt_rand();
        Fs::ensureDir($this->root);

        return $this->root;
    }
}
