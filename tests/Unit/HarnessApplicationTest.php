<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\HarnessApplication;
use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Sapi\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarnessApplicationTest extends TestCase
{
    private string $root = '';

    /** @return iterable<string, array{string, string}> */
    public static function interruptedRuns(): iterable
    {
        yield 'active run' => ['idle', 'run'];
        yield 'reduction run' => ['reduce', 'reduce'];
    }

    #[DataProvider('interruptedRuns')]
    public function testSingleInterruptCancelsWorkAndCleansDetachedServers(string $mode, string $prefix): void
    {
        $root = $this->workspace();
        mkdir($root, 0o700, true);
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/redis-client-tester-harness',
            '--no-core-check', '--no-tui', '--output=' . $root . '/campaign',
            '--runs=2', '--jobs=2', '--seed=42', '--steps=4', '--capture=failures', '--reduce=steps',
            '--', dirname(__DIR__) . '/Fixtures/sapi-server.php', 'harness', $mode, '{steps}',
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $root . '/output', 'w'],
            2 => ['file', $root . '/error', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 10;
            $directories = [];
            do {
                $directories = glob($root . '/campaign/.work/*/' . $prefix . '-*/sapi') ?: [];
                $ready = 0;
                foreach ($directories as $directory) {
                    $response = @file_get_contents($directory . '/request-0.php.http');
                    if ($response !== false && str_ends_with($response, 'idle')) {
                        $ready++;
                    }
                }
                if ($ready === ($mode === 'idle' ? 2 : 1)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertNotEmpty($directories, (string) file_get_contents($root . '/error'));
            self::assertSame($mode === 'idle' ? 2 : 1, $ready);
            self::assertStringEndsWith('idle', (string) file_get_contents($directories[0] . '/request-0.php.http'));
            proc_terminate($process, SIGINT);
            $deadline = microtime(true) + 5;
            do {
                $status = proc_get_status($process);
                usleep(10000);
            } while ($status['running'] && microtime(true) < $deadline);
            self::assertFalse($status['running']);
            self::assertSame($mode === 'reduce' ? 1 : 0, $status['exitcode']);
            self::assertFileExists($directories[0] . '/request-0.php.http');
            self::assertSame([], glob($root . '/campaign/failure/*/minimized'));
            self::assertCount($mode === 'reduce' ? 1 : 0, glob($root . '/campaign/failure/*') ?: []);
            foreach (glob($root . '/campaign/.work/*/*/sapi') ?: [] as $directory) {
                $pids = file($directory . '/pids.txt', FILE_IGNORE_NEW_LINES);
                self::assertIsArray($pids);
                self::assertGreaterThanOrEqual(3, count($pids));
                foreach ($pids as $pid) {
                    $stat = @file_get_contents('/proc/' . $pid . '/stat');
                    self::assertTrue($stat === false || str_contains($stat, ') Z '), 'surviving worker: ' . $pid);
                }
            }
        } finally {
            proc_terminate($process, SIGKILL);
            proc_close($process);
            foreach (glob($root . '/campaign/.work/*/*/sapi') ?: [] as $directory) {
                Server::stopOrphans($directory, true);
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            Fs::removeTree($this->root);
        }
    }

    public function testMissingTesterScriptIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--runs', '1',
            '--',
            'bin/redis-client-teste', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'fuzzer script not found: bin/redis-client-teste',
            self::contents($error),
        );
    }

    public function testMissingScriptIsDetectedBehindAnExplicitPhpInterpreter(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--',
            'php', 'bin/redis-client-teste',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'fuzzer script not found: bin/redis-client-teste',
            self::contents($error),
        );
    }

    public function testMissingPhpIniIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--php-ini', '/nonexistent/redis-client-tester.ini',
            '--runs', '1',
            '--',
            'bin/redis-client-tester', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'php ini file not found: /nonexistent/redis-client-tester.ini',
            self::contents($error),
        );
    }

    public function testAnUnwritableRunLogIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--run-log', '/nonexistent/directory/runs.log',
            '--output', $this->workspace(),
            '--no-core-check',
            '--runs', '1',
            '--',
            'bin/redis-client-tester', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'cannot open --run-log for appending: /nonexistent/directory/runs.log',
            self::contents($error),
        );
    }

    public function testHelpDocumentsTheRunLog(): void
    {
        $output = self::stream();

        $status = (new HarnessApplication($output, self::stream()))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('--run-log FILE', self::contents($output));
    }

    public function testUnavailableTargetAbortsBeforeCreatingCampaignArtifacts(): void
    {
        $root = $this->workspace();
        $error = self::stream();
        $status = (new HarnessApplication(self::stream(), $error))->run([
            '--no-core-check', '--port', '6379', '7000',
            '--output', $root . '/out', '--run-log', $root . '/runs.log',
            '--', 'bin/redis-client-tester', '--port={port}', '--host=' . $root . '/missing.sock',
        ]);

        self::assertSame(1, $status);
        $diagnostic = self::contents($error);
        self::assertStringContainsString('Redis/Valkey startup check failed', $diagnostic);
        self::assertStringContainsString($root . '/missing.sock:6379:', $diagnostic);
        self::assertStringContainsString($root . '/missing.sock:7000:', $diagnostic);
        self::assertStringContainsString('No fuzzing jobs were started.', $diagnostic);
        self::assertDirectoryDoesNotExist($root);
    }

    public function testHelpDocumentsThePhpStartupOptions(): void
    {
        $output = self::stream();

        $status = (new HarnessApplication($output, self::stream()))->run(['--help']);

        self::assertSame(0, $status);
        $help = self::contents($output);
        self::assertStringContainsString('--php-args ARGS', $help);
        self::assertStringContainsString('--php-ini FILE', $help);
        self::assertStringContainsString('{a,b,c}', $help);
        self::assertStringContainsString('{0.0-100.0}', $help);
    }

    public function testInvalidStartupRangesAreRejectedBeforeLaunchingWorkers(): void
    {
        $error = self::stream();
        $status = (new HarnessApplication(self::stream(), $error))->run([
            '--php-args=-d precision={9-1}',
            '--', \dirname(__DIR__) . '/Fixtures/startup-child.php',
        ]);
        self::assertSame(1, $status);
        self::assertStringContainsString('reversed startup range', self::contents($error));
    }

    public function testCampaignExpandsStartupArgumentsAndIniForProbesAndWorkers(): void
    {
        $root = $this->workspace();
        Fs::ensureDir($root);
        $ini = $root . '/template.ini';
        file_put_contents($ini, "precision={4-4}\n");
        $error = self::stream();
        $status = (new HarnessApplication(self::stream(), $error))->run([
            '--php-ini', $ini,
            '--php-args=-d serialize_precision={10-10}',
            '--no-core-check', '--keep-work', '--no-tui',
            '--seed', '123', '--runs', '1', '--jobs', '1',
            '--output', $root . '/out', '--run-log', $root . '/runs.log',
            '--', \dirname(__DIR__) . '/Fixtures/startup-child.php',
        ]);
        self::assertSame(0, $status, self::contents($error));
        $log = (string) file_get_contents($root . '/runs.log');
        self::assertStringContainsString('"precision":"4"', $log);
        self::assertStringContainsString('"serialize_precision":"10"', $log);
        self::assertSame("precision=4\n", file_get_contents($root . '/out/.work/' . getmypid() . '/probe/php.ini'));
        self::assertSame("precision=4\n", file_get_contents($root . '/out/.work/' . getmypid() . '/run-00001/php.ini'));
        self::assertSame("precision={4-4}\n", file_get_contents($ini));
    }

    public function testHelpDocumentsTheStartupFailureExitCode(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('A run that exits 78 is a startup failure', self::contents($output));
        self::assertSame('', self::contents($error));
    }

    public function testMemoryCaptureCountsAsAReproducerAndReducesOnlyMemoryFailures(): void
    {
        $root = $this->workspace();
        $output = self::stream();
        $error = self::stream();
        $status = (new HarnessApplication($output, $error))->run([
            '--capture', 'memory', '--reduce', 'steps', '--steps', '8',
            '--php-args=-d display_errors=stderr -d log_errors=0 -d html_errors=0',
            '--no-core-check', '--no-tui', '--quiet',
            '--seed', '123', '--reproducers', '1', '--jobs', '1',
            '--output', $root . '/out',
            '--', \dirname(__DIR__) . '/Fixtures/memory-child.php', '{steps}',
        ]);
        self::assertSame(1, $status, self::contents($error));
        $captures = glob($root . '/out/memory/*/minimized/meta.json');
        self::assertIsArray($captures);
        self::assertCount(1, $captures);
        $meta = json_decode((string) file_get_contents($captures[0]), true);
        self::assertIsArray($meta);
        self::assertSame(3, $meta['steps']);
        self::assertSame('memory', $meta['kind']);
    }

    private function workspace(): string
    {
        $this->root = sys_get_temp_dir() . '/redis-client-tester-harness-app-' . getmypid() . '-' . mt_rand();

        return $this->root;
    }

    /** @return resource */
    private static function stream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private static function contents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}
