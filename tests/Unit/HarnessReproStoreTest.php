<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\CorePattern;
use Mgrunder\PhpredisCommandFuzzer\Harness\FailureClassifier;
use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Harness\Job;
use Mgrunder\PhpredisCommandFuzzer\Harness\JobOutcome;
use Mgrunder\PhpredisCommandFuzzer\Harness\ReproStore;
use Mgrunder\PhpredisCommandFuzzer\Harness\RrTrace;
use PHPUnit\Framework\TestCase;

final class HarnessReproStoreTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            Fs::removeTree($this->root);
        }
    }

    public function testCaptureCopiesThePhpIniAndRecordsItInTheCommand(): void
    {
        $root = $this->workspace();
        $ini = $root . '/relay-fuzz.ini';
        file_put_contents($ini, "relay.maxmemory=1G\n");

        $dir = $this->store($root, $ini)->capture($this->job($root, ['php', '-c', $ini, 'fuzz']), []);

        self::assertFileExists($dir . '/php.ini');
        self::assertSame("relay.maxmemory=1G\n", file_get_contents($dir . '/php.ini'));

        $command = (string) file_get_contents($dir . '/command.txt');
        self::assertStringContainsString("# ini:   {$ini} (copied to php.ini)", $command);
    }

    public function testCaptureWithoutAnIniWritesNoIniCopy(): void
    {
        $root = $this->workspace();

        $dir = $this->store($root, null)->capture($this->job($root, ['php', 'fuzz']), []);

        self::assertFileDoesNotExist($dir . '/php.ini');
        self::assertStringNotContainsString('# ini:', (string) file_get_contents($dir . '/command.txt'));
    }

    public function testAFailedCaptureIsParkedUnderFailedWithItsReason(): void
    {
        $root = $this->workspace();
        $store = $this->store($root, null);

        $good = $store->capture($this->job($root, ['php', 'fuzz']), []);
        $bad = $store->captureFailed($this->job($root, ['php', 'fuzz']), [], 'rr trace never finalised');

        self::assertSame($root . '/out', dirname($good));
        self::assertSame($root . '/out/' . ReproStore::FAILED, dirname($bad));

        // Each directory keeps its own sequence, so a failed capture never
        // consumes a reproducer number (or the other way round).
        self::assertSame('4242.00001', basename($good));
        self::assertSame('4242.00001', basename($bad));

        self::assertStringContainsString('rr trace never finalised', (string) file_get_contents($bad . '/FAILED.txt'));

        $meta = json_decode((string) file_get_contents($bad . '/meta.json'), true);
        self::assertIsArray($meta);
        self::assertSame('rr trace never finalised', $meta['capture_failure']);
    }

    public function testAMinimizedRerunNeverFilesAnUnfinalisedTrace(): void
    {
        $root = $this->workspace();
        $store = $this->store($root, null);
        $dir = $store->capture($this->job($root, ['php', 'fuzz']), []);

        $store->addMinimized($dir, $this->outcome($root, incomplete: true), 12);

        self::assertDirectoryDoesNotExist($dir . '/minimized/rr-trace');
        $meta = json_decode((string) file_get_contents($dir . '/minimized/meta.json'), true);
        self::assertIsArray($meta);
        self::assertSame('rr trace never finalised (incomplete)', $meta['trace_failure']);
    }

    public function testAMinimizedRerunKeepsAFinalisedTrace(): void
    {
        $root = $this->workspace();
        $store = $this->store($root, null);
        $dir = $store->capture($this->job($root, ['php', 'fuzz']), []);

        $store->addMinimized($dir, $this->outcome($root, incomplete: false), 12);

        self::assertDirectoryExists($dir . '/minimized/rr-trace');
        $meta = json_decode((string) file_get_contents($dir . '/minimized/meta.json'), true);
        self::assertIsArray($meta);
        self::assertNull($meta['trace_failure']);
    }

    private function outcome(string $root, bool $incomplete): JobOutcome
    {
        $workDir = $root . '/reduce';
        $trace = $workDir . '/rr-trace';
        Fs::ensureDir($trace);
        file_put_contents($workDir . '/stdout.log', '');
        file_put_contents($workDir . '/stderr.log', '');
        if ($incomplete) {
            file_put_contents($trace . '/' . RrTrace::SENTINEL, '1');
        }

        return new JobOutcome(
            FailureClassifier::fromExit(true, 11, null),
            $workDir,
            $trace,
            0.5,
            null,
        );
    }

    private function workspace(): string
    {
        $this->root = sys_get_temp_dir() . '/phpredis-fuzz-repro-test-' . getmypid() . '-' . mt_rand();
        Fs::ensureDir($this->root);

        return $this->root;
    }

    private function store(string $root, ?string $ini): ReproStore
    {
        return new ReproStore($root . '/out', new CorePattern('core.%e.%p', false), $root, $ini, 4242);
    }

    /** @param list<string> $argv */
    private function job(string $root, array $argv): Job
    {
        $workDir = $root . '/work';
        Fs::ensureDir($workDir);
        file_put_contents($workDir . '/stdout.log', '');
        file_put_contents($workDir . '/stderr.log', '');

        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);

        return new Job(
            id: 1,
            slot: 0,
            port: 7000,
            steps: 100,
            seed: 5,
            workDir: $workDir,
            traceDir: null,
            argv: $argv,
            startedAt: microtime(true),
            process: $handle,
        );
    }
}
