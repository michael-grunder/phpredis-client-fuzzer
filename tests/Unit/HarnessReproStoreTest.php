<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\CorePattern;
use Mgrunder\RedisClientTester\Harness\FailureClassifier;
use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Harness\Job;
use Mgrunder\RedisClientTester\Harness\JobOutcome;
use Mgrunder\RedisClientTester\Harness\ReproStore;
use Mgrunder\RedisClientTester\Harness\RrTrace;
use PHPUnit\Framework\TestCase;

final class HarnessReproStoreTest extends TestCase
{
    private string $root = '';

    public function testSapiScriptsResponsesAndWorkerCoresAreRetained(): void
    {
        $root = $this->workspace();
        $job = $this->job($root, ['php', 'bin/redis-client-tester-sapi', '--', '--seed=5']);
        mkdir($job->workDir . '/sapi');
        file_put_contents($job->workDir . '/sapi/pids.txt', "12345\n12346\n");
        file_put_contents($job->workDir . '/sapi/request-1.php', '<?php echo "workload";');
        file_put_contents($job->workDir . '/sapi/request-1.php.http', 'partial response');
        file_put_contents($root . '/core.php.12346', 'worker core');
        file_put_contents($root . '/core.php.98765', 'unrelated core');
        $dir = $this->store($root, null)->capture($job, ['kind' => 'crash']);
        self::assertSame('worker core', file_get_contents($dir . '/core.php.12346'));
        self::assertFileExists($root . '/core.php.98765');
        self::assertFileExists($dir . '/sapi/request-1.php');
        self::assertSame('partial response', file_get_contents($dir . '/sapi/request-1.php.http'));
        self::assertStringContainsString('REDIS_CLIENT_TESTER_PHP_ARGS=', (string) file_get_contents($dir . '/command.txt'));
    }

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

        self::assertSame($root . '/out/failure', dirname($good));
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

    public function testCapturesAreGroupedByKindWithoutOverwritingExistingEvidence(): void
    {
        $root = $this->workspace();
        $store = $this->store($root, null);
        $legacy = $root . '/out/4242.00001';
        Fs::ensureDir($legacy);
        file_put_contents($legacy . '/stdout.log', 'legacy evidence');

        foreach (['crash', 'memory', 'leak', 'timeout', 'failure'] as $kind) {
            $first = $store->capture($this->job($root, ['php', 'fuzz']), ['kind' => $kind]);
            self::assertSame($root . '/out/' . $kind . '/4242.00001', $first);
            file_put_contents($first . '/stdout.log', 'original ' . $kind);

            // A restarted campaign with a recycled pid must skip existing captures.
            $second = $this->store($root, null)->capture(
                $this->job($root, ['php', 'fuzz']),
                ['kind' => $kind],
            );
            self::assertSame($root . '/out/' . $kind . '/4242.00002', $second);
            self::assertSame('original ' . $kind, file_get_contents($first . '/stdout.log'));
        }

        self::assertSame('legacy evidence', file_get_contents($legacy . '/stdout.log'));
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
        $this->root = sys_get_temp_dir() . '/redis-client-tester-repro-test-' . getmypid() . '-' . mt_rand();
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
