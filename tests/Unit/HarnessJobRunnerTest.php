<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\CommandTemplate;
use Mgrunder\PhpredisCommandFuzzer\Harness\CorePattern;
use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Harness\HarnessOptions;
use Mgrunder\PhpredisCommandFuzzer\Harness\Job;
use Mgrunder\PhpredisCommandFuzzer\Harness\JobRunner;
use Mgrunder\PhpredisCommandFuzzer\Harness\JobStatus;
use Mgrunder\PhpredisCommandFuzzer\Harness\ReproStore;
use Mgrunder\PhpredisCommandFuzzer\Harness\RunLog;
use PHPUnit\Framework\TestCase;

/**
 * Runs real child processes (plain PHP one-liners and a shell stand-in for
 * `rr record`); no Redis server is involved.
 */
final class HarnessJobRunnerTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            Fs::removeTree($this->root);
        }
    }

    public function testAnUnfinalisedTraceIsParkedUnderFailedInsteadOfBecomingAReproducer(): void
    {
        $root = $this->workspace();
        $runner = $this->runner($root, ['--rr'], $this->child(0), $this->fakeRr('quiet-abort'));

        $job = $this->await($runner, $runner->spawn(0, null, 7));

        // Nothing in stderr says the recorder was at fault, so this stays a
        // failure — one whose evidence simply cannot be replayed.
        self::assertSame(JobStatus::CaptureFailed, $job->status);
        self::assertNotNull($job->reproDir);
        self::assertSame($root . '/out/' . ReproStore::FAILED, dirname($job->reproDir));
        self::assertFileExists($job->reproDir . '/FAILED.txt');
        self::assertFileExists($job->reproDir . '/rr-trace/incomplete');
        self::assertStringContainsString('never finalised', (string) $job->traceFailure);
        self::assertStringContainsString('incomplete', $job->note);

        $meta = $this->meta($job->reproDir);
        self::assertSame('incomplete', $meta['rr_trace']);
        self::assertIsString($meta['capture_failure']);
        self::assertStringContainsString('never finalised', $meta['capture_failure']);
    }

    public function testRrDyingBeforeItRecordedAnythingIsDiscardedAsRecorderNoise(): void
    {
        $root = $this->workspace();
        $runner = $this->runner($root, ['--rr'], $this->child(0), $this->fakeRr('fatal'));

        $job = $this->await($runner, $runner->spawn(0, null, 7));

        // rr's SIGABRT is not the client's crash, so the run is neither a
        // failure nor a capture: only rr's own diagnostic is kept.
        self::assertSame(JobStatus::RrAborted, $job->status);
        self::assertFalse($job->failure);
        self::assertNull($job->reproDir);
        self::assertStringContainsString('STOP-SIGWINCH', (string) $job->rrFatal);
        self::assertStringContainsString('rr aborted while recording', $job->note);
        self::assertDirectoryDoesNotExist($job->workDir);
        self::assertDirectoryDoesNotExist($root . '/out/' . ReproStore::FAILED);
    }

    public function testRrDyingAfterItFinalisedTheTraceIsAlsoDiscarded(): void
    {
        $root = $this->workspace();
        $runner = $this->runner($root, ['--rr'], $this->child(0), $this->fakeRr('late-fatal'));

        $job = $this->await($runner, $runner->spawn(0, null, 7));

        // The trace is complete, so only rr's own backtrace in stderr says the
        // SIGABRT belongs to the recorder rather than to the client.
        self::assertSame(JobStatus::RrAborted, $job->status);
        self::assertFalse($job->failure);
        self::assertNull($job->reproDir);
        self::assertStringContainsString('backtrace', (string) $job->rrFatal);
        self::assertDirectoryDoesNotExist($root . '/out/' . ReproStore::FAILED);
    }

    public function testKeepWorkPreservesTheArtifactsOfARunRrAbortedOn(): void
    {
        $root = $this->workspace();
        $runner = $this->runner($root, ['--rr', '--keep-work'], $this->child(0), $this->fakeRr('fatal'));

        $job = $this->await($runner, $runner->spawn(0, null, 7));

        self::assertSame(JobStatus::RrAborted, $job->status);
        self::assertFileExists($job->workDir . '/stderr.log');
        self::assertFileExists($job->workDir . '/rr-trace/incomplete');
    }

    public function testAFinalisedTraceIsCapturedAsAnOrdinaryReproducer(): void
    {
        $root = $this->workspace();
        $runner = $this->runner(
            $root,
            ['--rr', '--capture', 'failures'],
            $this->child(3),
            $this->fakeRr('ok'),
        );

        $job = $this->await($runner, $runner->spawn(0, null, 7));

        self::assertSame(JobStatus::Failed, $job->status);
        self::assertNotNull($job->reproDir);
        self::assertSame($root . '/out', dirname($job->reproDir));
        self::assertDirectoryExists($job->reproDir . '/rr-trace');
        self::assertFileDoesNotExist($job->reproDir . '/rr-trace/incomplete');
        self::assertNull($job->traceFailure);
        self::assertSame('complete', $this->meta($job->reproDir)['rr_trace']);
    }

    public function testShutdownStillCapturesARunThatHadAlreadyFailed(): void
    {
        $root = $this->workspace();
        $runner = $this->runner($root, ['--capture', 'failures'], $this->child(3));

        $job = $runner->spawn(0, null, 7);
        self::assertNotNull($job->pid);
        self::assertTrue($this->waitForExit($job->pid), 'the child did not exit in time');

        $runner->poll($job, true);

        self::assertSame(JobStatus::Failed, $job->status);
        self::assertNotNull($job->reproDir);
        self::assertSame(3, $job->exitCode);
    }

    public function testShutdownDiscardsARunItHadToKill(): void
    {
        $root = $this->workspace();
        $runner = $this->runner($root, ['--capture', 'failures'], $this->child(0, 30.0));

        $job = $runner->spawn(0, null, 7);
        $runner->poll($job, true);

        self::assertSame(JobStatus::Skipped, $job->status);
        self::assertSame('stopped', $job->note);
        self::assertNull($job->reproDir);
    }

    public function testTheRunLogKeepsTheOutputOfARunThatPassedAndWasDiscarded(): void
    {
        $root = $this->workspace();
        $log = new RunLog($root . '/runs.log', 4242);
        $runner = $this->runner($root, [], $this->child(0), null, $log);

        $job = $this->await($runner, $runner->spawn(0, 7000, 7));

        self::assertSame(JobStatus::Passed, $job->status);
        self::assertDirectoryDoesNotExist($job->workDir);

        // The work directory is gone, so this file is the only place the run's
        // output still exists.
        $contents = (string) file_get_contents($log->path());
        self::assertStringContainsString('run=1 seed=7 port=7000', $contents);
        self::assertStringContainsString(':: ok', $contents);
        self::assertStringContainsString('harness-child exiting with 0', $contents);
    }

    public function testTheRunLogLabelsAFailureAndStillCapturesItAsAReproducer(): void
    {
        $root = $this->workspace();
        $log = new RunLog($root . '/runs.log', 4242);
        $runner = $this->runner($root, ['--capture', 'failures'], $this->child(3), null, $log);

        $job = $this->await($runner, $runner->spawn(0, null, 7));

        self::assertSame(JobStatus::Failed, $job->status);
        self::assertNotNull($job->reproDir);
        self::assertFileExists($job->reproDir . '/stdout.log');

        $contents = (string) file_get_contents($log->path());
        self::assertStringContainsString(':: exit 3', $contents);
        self::assertStringContainsString('harness-child exiting with 3', $contents);
    }

    public function testTheRunLogRecordsWhatARunKilledAtShutdownHadPrinted(): void
    {
        $root = $this->workspace();
        $log = new RunLog($root . '/runs.log', 4242);
        $runner = $this->runner($root, [], $this->child(0, 30.0), null, $log);

        $job = $runner->spawn(0, null, 7);
        self::assertTrue($this->waitForOutput($job), 'the child never printed anything');
        $runner->poll($job, true);

        self::assertSame(JobStatus::Skipped, $job->status);

        // The run never finished, but whatever it had already printed is kept.
        $contents = (string) file_get_contents($log->path());
        self::assertStringContainsString(':: stopped', $contents);
        self::assertStringContainsString('harness-child exiting with 0', $contents);
    }

    public function testReductionRerunsAreNotLogged(): void
    {
        $root = $this->workspace();
        $log = new RunLog($root . '/runs.log', 4242);
        $runner = $this->runner($root, ['--capture', 'failures'], $this->child(3), null, $log);

        $runner->rerun(7, null, 1, 30.0, false);

        // A reduction replays the same seed a dozen times; only the campaign's
        // own runs belong in the log.
        self::assertFileDoesNotExist($log->path());
    }

    private function await(JobRunner $runner, Job $job): Job
    {
        $deadline = microtime(true) + 30.0;
        while ($job->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
            $runner->poll($job);
        }
        self::assertFalse($job->isRunning(), 'the run never finished');

        return $job;
    }

    /** Wait until the still-running child has flushed something to stdout. */
    private function waitForOutput(Job $job, float $seconds = 30.0): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $job->workDir . '/stdout.log');
            if ((int) @filesize($job->workDir . '/stdout.log') > 0) {
                return true;
            }
            usleep(10_000);
        }

        return false;
    }

    /**
     * A child of ours is reaped by proc_close(), so "has exited" is visible in
     * /proc as the zombie state rather than through proc_get_status(), whose
     * first call would consume the exit code the runner still needs.
     */
    private function waitForExit(int $pid, float $seconds = 30.0): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if (!is_string($stat)) {
                return true;
            }
            if (preg_match('/\)\s+(\S)/', $stat, $matches) === 1 && $matches[1] === 'Z') {
                return true;
            }
            usleep(10_000);
        }

        return false;
    }

    /** @return array<mixed> */
    private function meta(string $dir): array
    {
        $decoded = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<string> $harnessArgs
     * @param list<string> $command
     */
    private function runner(
        string $root,
        array $harnessArgs,
        array $command,
        ?string $rr = null,
        ?RunLog $runLog = null,
    ): JobRunner {
        $options = HarnessOptions::parse([...$harnessArgs, '--steps', '1', '--', ...$command]);
        $store = new ReproStore($root . '/out', new CorePattern('core.%e.%p', false), $root, null, 4242);

        return new JobRunner(
            $options,
            PHP_BINARY,
            [],
            $root,
            new CommandTemplate($command),
            $store,
            $root . '/work',
            $rr,
            'php test',
            null,
            $runLog,
        );
    }

    /**
     * A template that runs the child fixture: exit with $code after $sleep
     * seconds.
     *
     * @return list<string>
     */
    private function child(int $code, float $sleep = 0.0): array
    {
        return ['php', \dirname(__DIR__) . '/Fixtures/harness-child.php', (string) $code, (string) $sleep];
    }

    private function fakeRr(string $variant): string
    {
        $path = \dirname(__DIR__) . '/Fixtures/fake-rr-' . $variant . '.sh';
        self::assertFileExists($path);
        @chmod($path, 0o755);

        return $path;
    }

    private function workspace(): string
    {
        $this->root = sys_get_temp_dir() . '/phpredis-fuzz-runner-' . getmypid() . '-' . mt_rand();
        Fs::ensureDir($this->root);

        return $this->root;
    }
}
