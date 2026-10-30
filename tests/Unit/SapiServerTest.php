<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\FailureClassifier;
use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Sapi\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SapiServerTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        try {
            Server::assertSupported();
        } catch (\RuntimeException $error) {
            self::markTestSkipped($error->getMessage());
        }
        $this->directory = sys_get_temp_dir() . '/sapi-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            Fs::removeTree($this->directory);
        }
    }

    public function testConcurrentRequestsReuseWorkersAndHonorStartupArguments(): void
    {
        self::assertSame(0, $this->runFixture('normal'));
        $lines = file($this->directory . '/events.jsonl', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(16, $lines);
        $active = 0;
        $maximum = 0;
        $pids = [];
        foreach ($lines as $line) {
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($event);
            self::assertIsInt($event[1]);
            $active += $event[0] === 'start' ? 1 : -1;
            $maximum = max($maximum, $active);
            $pids[$event[1]] = true;
        }
        self::assertSame(2, $maximum);
        self::assertLessThanOrEqual(3, count($pids));
        self::assertGreaterThan(1, count($pids));
        for ($index = 0; $index < 8; $index++) {
            $response = file_get_contents($this->directory . "/request-{$index}.php.http");
            self::assertIsString($response);
            self::assertStringContainsString('cli-server:12', $response);
            self::assertStringContainsString('X-Fuzzer-Pid:', $response);
        }
        $this->assertStopped();
    }

    public function testSeededScriptsAreStableAndStartupErrorsPropagateWithoutRedis(): void
    {
        foreach (['first', 'second'] as $name) {
            $process = proc_open([
                PHP_BINARY, dirname(__DIR__, 2) . '/bin/sapi',
                '--directory=' . $this->directory . '/' . $name,
                '--requests=3', '--workers=2', '--idle-ms=0', '--',
                '--seed=42', '--client=invalid-client', '--commands=get,set', '--weight=get:2',
            ], [
                1 => ['file', $this->directory . '/' . $name . '.out', 'w'],
                2 => ['file', $this->directory . '/' . $name . '.err', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            self::assertSame(78, proc_close($process));
        }
        $paths = glob($this->directory . '/first/request-*.php');
        self::assertIsArray($paths);
        self::assertCount(3, $paths);
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringContainsString('--persistent', $source);
            self::assertSame($source, file_get_contents($this->directory . '/second/' . basename($path)));
        }
        self::assertStringContainsString('invalid-client', (string) file_get_contents($this->directory . '/first.err'));
    }

    public function testStepJitterPreservesSeedsAndScalesBudgetsDuringReduction(): void
    {
        $runs = [
            'plain' => [null, 1000], 'zero' => [0, 1000],
            'jitter' => [90, 1000], 'repeat' => [90, 1000], 'reduced' => [90, 100],
            'rounded' => [90, 3], 'single' => [100, 1], 'unlimited' => [0, 0], 'huge' => [90, PHP_INT_MAX],
        ];
        $budgets = array_fill_keys(array_keys($runs), []);
        $seeds = array_fill_keys(array_keys($runs), []);
        foreach ($runs as $name => [$jitter, $steps]) {
            self::assertSame(78, $this->runApplication($name, [
                ...($jitter === null ? [] : ['--step-jitter=' . $jitter]),
                '--', '--steps=' . $steps, '--seconds=1', '--seed=42', '--client=invalid-client',
            ]));
            $manifest = json_decode((string) file_get_contents($this->directory . '/' . $name . '/workload.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($manifest);
            self::assertSame($jitter ?? 0, $manifest['step_jitter']);
            self::assertIsArray($manifest['request_steps']);
            self::assertIsArray($manifest['request_seeds']);
            $seeds[$name] = $manifest['request_seeds'];
            self::assertCount(20, $manifest['request_steps']);
            foreach ($manifest['request_steps'] as $index => $budget) {
                self::assertIsInt($index);
                self::assertIsInt($budget);
                $budgets[$name][] = $budget;
                self::assertGreaterThanOrEqual($steps === 0 ? 0 : 1, $budget);
                self::assertLessThanOrEqual($steps, $budget);
                if ($jitter !== null && $jitter > 0) {
                    self::assertIsInt($seeds[$name][$index]);
                    $script = sprintf('%s/%s/request-%05d-seed-%d.php', $this->directory, $name, $index + 1, $seeds[$name][$index]);
                    self::assertStringContainsString("'--steps={$budget}'", (string) file_get_contents($script));
                }
            }
            self::assertSame($seeds['plain'], $seeds[$name]);
        }
        self::assertSame(array_fill(0, 20, 1000), $budgets['plain']);
        self::assertSame($budgets['plain'], $budgets['zero']);
        self::assertSame($budgets['jitter'], $budgets['repeat']);
        self::assertGreaterThan(1, count(array_unique($budgets['jitter'])));
        self::assertSame(array_fill(0, 20, 1), $budgets['single']);
        self::assertSame(array_fill(0, 20, 0), $budgets['unlimited']);
        foreach ($budgets['jitter'] as $index => $budget) {
            self::assertGreaterThanOrEqual(100, $budget);
            self::assertSame($budget, $budgets['reduced'][$index] * 10);
            self::assertSame(max(1, (int) ceil($budget * 3 / 1000)), $budgets['rounded'][$index]);
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidJitter(): iterable
    {
        foreach (['-1', '101', 'NaN', '1.5'] as $value) {
            yield $value => [['--step-jitter=' . $value, '--', '--seed=42']];
        }
        yield 'unlimited steps' => [['--step-jitter=90', '--', '--steps=0', '--seconds=1', '--seed=42']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidJitter')]
    public function testInvalidJitterIsRejectedBeforeGeneratingScripts(array $arguments): void
    {
        self::assertSame(78, $this->runApplication('invalid', $arguments));
        self::assertDirectoryDoesNotExist($this->directory . '/invalid');
        self::assertStringContainsString('--step-jitter', (string) file_get_contents($this->directory . '/invalid.err'));
    }

    /** @param list<string> $arguments */
    private function runApplication(string $name, array $arguments): int
    {
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/sapi',
            '--directory=' . $this->directory . '/' . $name,
            '--workers=2', '--idle-ms=0', ...$arguments,
        ], [
            1 => ['file', $this->directory . '/' . $name . '.out', 'w'],
            2 => ['file', $this->directory . '/' . $name . '.err', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        return proc_close($process);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function failures(): iterable
    {
        yield 'timeout' => ['timeout', 124, 'timeout'];
        yield 'worker signal' => ['crash', 139, 'crash'];
        yield 'PHP fatal' => ['fatal', 1, 'failure'];
        yield 'configuration error' => ['startup', 78, 'startup'];
        yield 'Valgrind worker errors' => ['valgrind', 1, 'failure'];
        yield 'fatal after HTTP success' => ['shutdown-fatal', 1, 'failure'];
    }

    #[DataProvider('failures')]
    public function testFailuresAreNotSuccessfulHttpResponses(string $mode, int $exit, string $kind): void
    {
        self::assertSame($exit, $this->runFixture($mode));
        self::assertSame($kind, FailureClassifier::fromExit(false, null, $exit)->kind());
        self::assertFileExists($this->directory . '/request-0.php.http');
        self::assertFileExists($this->directory . '/server.stderr.log');
        $this->assertStopped();
    }

    private function runFixture(string $mode): int
    {
        return proc_close($this->launchFixture($mode));
    }

    /** @return resource */
    private function launchFixture(string $mode)
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/sapi-server.php', $this->directory, $mode], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . '/stdout.log', 'w'],
            2 => ['file', $this->directory . '/stderr.log', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        return $process;
    }

    public function testIdleWorkerDeathIsDetectedWithoutAnotherRequest(): void
    {
        $process = $this->launchFixture('idle');
        try {
            $this->waitForIdle();
            $pids = file($this->directory . '/pids.txt', FILE_IGNORE_NEW_LINES);
            self::assertIsArray($pids);
            self::assertGreaterThanOrEqual(3, count($pids));
            self::assertTrue(posix_kill((int) $pids[1], SIGKILL));
            self::assertSame(137, proc_close($process));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->assertStopped();
    }

    public function testHarnessCanCleanUpAfterSupervisorIsKilled(): void
    {
        $process = $this->launchFixture('idle');
        try {
            $this->waitForIdle();
            proc_terminate($process, SIGKILL);
            proc_close($process);
        } finally {
            Server::stopOrphans($this->directory);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->assertStopped();
    }

    public function testCleanupFindsWorkersEvenWhenOnlyTheDeadParentWasRecorded(): void
    {
        $process = $this->launchFixture('idle');
        $pids = false;
        try {
            $this->waitForIdle();
            $pids = file_get_contents($this->directory . '/pids.txt');
            self::assertIsString($pids);
            $parent = (int) strtok($pids, "\n");
            proc_terminate($process, SIGKILL);
            proc_close($process);
            posix_kill($parent, SIGKILL);
            file_put_contents($this->directory . '/pids.txt', $parent . "\n");
            Server::stopOrphans($this->directory, true);
            file_put_contents($this->directory . '/pids.txt', $pids);
            $this->assertStopped();
        } finally {
            if (is_string($pids)) {
                file_put_contents($this->directory . '/pids.txt', $pids);
            }
            Server::stopOrphans($this->directory);
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
        }
    }

    private function waitForIdle(): void
    {
        $deadline = microtime(true) + 5.0;
        do {
            $response = @file_get_contents($this->directory . '/request-0.php.http');
            if ($response !== false && str_ends_with($response, 'idle')) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('fixture did not reach its idle interval');
    }

    private function assertStopped(): void
    {
        $pids = file($this->directory . '/pids.txt', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($pids);
        foreach ($pids as $pid) {
            $deadline = microtime(true) + 2;
            do {
                $stat = @file_get_contents('/proc/' . $pid . '/stat');
                if ($stat === false || str_contains($stat, ') Z ')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($stat !== false) {
                self::assertStringContainsString(') Z ', $stat, 'server process still alive: ' . $pid);
            }
        }
    }
}
