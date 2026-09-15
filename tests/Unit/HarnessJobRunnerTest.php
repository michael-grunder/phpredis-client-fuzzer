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
use PHPUnit\Framework\Attributes\DataProvider;

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
        self::assertSame($root . '/out/failure', dirname($job->reproDir));
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

    public function testStartupChoicesAreCapturedAndRepeatedDuringReduction(): void
    {
        $root = $this->workspace();
        $ini = $root . '/template.ini';
        $source = "precision={4-14}\nserialize_precision={5,6,7}\ninclude_path=\"{a b,c d}\"\nmemory_limit={16m-32m}\n";
        file_put_contents($ini, $source);
        $runner = $this->runner(
            $root,
            ['--php-ini', $ini, '--php-args=-d serialize_precision={10-17} -d memory_limit={32m-64m}'],
            [\dirname(__DIR__) . '/Fixtures/startup-child.php', '139'],
        );

        $first = $runner->spawn(0, null, 7);
        $second = $runner->spawn(1, null, 8);
        self::assertNotSame($first->phpIni, $second->phpIni);
        $job = $this->await($runner, $first);
        $this->await($runner, $second);
        self::assertSame(JobStatus::Crashed, $job->status);
        self::assertNotNull($job->reproDir);
        self::assertDirectoryDoesNotExist($job->workDir);
        self::assertSame($source, file_get_contents($ini));

        $captured = (string) file_get_contents($job->reproDir . '/php.ini');
        self::assertStringNotContainsString('{', $captured);
        $output = json_decode((string) file_get_contents($job->reproDir . '/stdout.log'), true);
        self::assertIsArray($output);
        self::assertSame($captured, $output['ini_contents']);
        self::assertContains($output['include_path'], ['a b', 'c d']);
        self::assertIsString($output['serialize_precision']);
        self::assertContains('serialize_precision=' . $output['serialize_precision'], $job->phpArgs);
        self::assertIsString($output['memory_limit']);
        self::assertContains('memory_limit=' . $output['memory_limit'], $job->phpArgs);
        self::assertGreaterThanOrEqual(32 * 1024 * 1024, (int) $output['memory_limit']);
        self::assertLessThanOrEqual(64 * 1024 * 1024, (int) $output['memory_limit']);
        $iniValues = parse_ini_string($captured);
        self::assertIsArray($iniValues);
        self::assertIsString($iniValues['memory_limit']);
        self::assertGreaterThanOrEqual(16 * 1024 * 1024, (int) $iniValues['memory_limit']);
        self::assertLessThanOrEqual(32 * 1024 * 1024, (int) $iniValues['memory_limit']);
        self::assertSame($job->phpArgs, $this->meta($job->reproDir)['php_args']);
        self::assertSame($job->argv, $this->meta($job->reproDir)['command']);
        $command = explode("\n", (string) file_get_contents($job->reproDir . '/command.txt'))[0];
        self::assertStringContainsString($job->reproDir . '/php.ini', $command);
        self::assertStringNotContainsString($job->workDir, $command);

        // Reduction must reuse the original snapshot, even if the user edits
        // the source while the campaign is running.
        file_put_contents($ini, "precision=99\n");
        $outcome = $runner->rerun(7, null, 1, 30.0, false);
        self::assertSame($captured, file_get_contents($outcome->workDir . '/php.ini'));
        self::assertSame($output, json_decode((string) file_get_contents($outcome->workDir . '/stdout.log'), true));
        $store = new ReproStore($root . '/out', new CorePattern('core.%e.%p', false), $root, $ini);
        $store->addMinimized($job->reproDir, $outcome, 1);
        self::assertSame($captured, file_get_contents($job->reproDir . '/minimized/php.ini'));
    }

    public function testPhpArgsInjectionWorksWithoutAnIniAndSuccessfulWorkIsCleaned(): void
    {
        $root = $this->workspace();
        $log = new RunLog($root . '/runs.log', 4242);
        $runner = $this->runner($root, ['--php-args=-d precision={4-4}'], [\dirname(__DIR__) . '/Fixtures/startup-child.php'], null, $log);
        $job = $this->await($runner, $runner->spawn(0, null, 7));
        self::assertSame(JobStatus::Passed, $job->status);
        self::assertNull($job->phpIni);
        self::assertDirectoryDoesNotExist($job->workDir);
        self::assertStringContainsString('"precision":"4"', (string) file_get_contents($log->path()));
    }

    #[DataProvider('memoryOutputs')]
    public function testMemoryLimitFailureIsCapturedFromEitherOutput(string $display, string $logFile): void
    {
        $root = $this->workspace();
        $log = new RunLog($root . '/runs.log', 4242);
        $runner = $this->runner(
            $root,
            ['--capture', 'memory', '--php-args=-d display_errors=' . $display . ' -d log_errors=0 -d html_errors=0'],
            [\dirname(__DIR__) . '/Fixtures/memory-child.php', '3'],
            null,
            $log,
        );
        $job = $this->await($runner, $runner->spawn(0, null, 7));
        self::assertSame(JobStatus::MemoryFailed, $job->status);
        self::assertSame(255, $job->exitCode);
        self::assertNotNull($job->reproDir);
        $meta = $this->meta($job->reproDir);
        self::assertSame('memory', $meta['kind']);
        self::assertTrue($meta['memory']);
        self::assertIsString($meta['memory_message']);
        self::assertStringContainsString('Allowed memory size', $meta['memory_message']);
        self::assertStringContainsString('Allowed memory size', (string) file_get_contents($job->reproDir . '/' . $logFile));
        self::assertStringContainsString(':: memory', (string) file_get_contents($log->path()));

        $outcome = $runner->rerun(7, null, 1, 10.0, false);
        self::assertSame('memory', $outcome->verdict->kind());
        self::assertNotNull($outcome->memory);
        $store = new ReproStore($root . '/out', new CorePattern('core.%e.%p', false), $root);
        $store->addMinimized($job->reproDir, $outcome, 1);
        self::assertSame($meta['memory_message'], $this->meta($job->reproDir . '/minimized')['memory_message']);
        self::assertSame('memory', $this->meta($job->reproDir . '/minimized')['kind']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function memoryOutputs(): iterable
    {
        yield 'stdout' => ['1', 'stdout.log'];
        yield 'stderr' => ['stderr', 'stderr.log'];
    }

    public function testMemoryFailureIsNotCapturedAsAnOrdinaryFailure(): void
    {
        $root = $this->workspace();
        $runner = $this->runner(
            $root,
            ['--capture', 'failures', '--php-args=-d display_errors=stderr -d log_errors=0'],
            [\dirname(__DIR__) . '/Fixtures/memory-child.php', '3'],
        );
        $job = $this->await($runner, $runner->spawn(0, null, 7));
        self::assertSame(JobStatus::Skipped, $job->status);
        self::assertSame('memory (not captured)', $job->note);
        self::assertTrue($job->failure);
        self::assertNull($job->reproDir);
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
            $options->phpArgs,
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
