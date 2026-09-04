<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\CorePattern;
use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Harness\Job;
use Mgrunder\PhpredisCommandFuzzer\Harness\ReproStore;
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
