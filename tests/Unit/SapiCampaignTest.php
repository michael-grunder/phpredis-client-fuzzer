<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SapiCampaignTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function exits(): iterable
    {
        foreach (['asan-sapi.sh', 'asan-saturate-sapi.sh'] as $script) {
            foreach (['normal' => 7, 'crash' => 137, 'interrupt' => 130, 'terminate' => 143] as $mode => $status) {
                yield $script . ':' . $mode => [$script, $mode, $status];
            }
        }
    }

    #[DataProvider('exits')]
    public function testWrapperCleansDetachedWorkers(string $script, string $mode, int $expected): void
    {
        $source = dirname(__DIR__, 2) . '/local-scripts/' . $script;
        if (!is_file($source)) {
            self::markTestSkipped('Local wrapper is not installed: ' . $script);
        }
        $root = sys_get_temp_dir() . '/sapi-campaign-test-' . bin2hex(random_bytes(6));
        mkdir($root, 0o700);
        mkdir($root . '/bin');
        mkdir($root . '/scripts');
        mkdir($root . '/local-scripts');
        copy($source, $root . '/local-scripts/' . $script);
        copy(dirname(__DIR__, 2) . '/scripts/sapi-campaign.sh', $root . '/scripts/sapi-campaign.sh');
        file_put_contents($root . '/bin/redis-client-tester-harness', '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
pcntl_async_signals(true);
$mode = getenv('TEST_MODE');
$interrupts = 0;
pcntl_signal(SIGTERM, static function () use (&$interrupts, $mode): void {
    if ($mode === 'interrupt') {
        file_put_contents('interrupts', (string) ++$interrupts);
        if ($interrupts === 2) {
            exit(130);
        }
    }
});
$pid = pcntl_fork();
if ($pid === 0) {
    posix_setsid();
    pcntl_signal(SIGTERM, SIG_IGN);
    file_put_contents('worker.pid', (string) getmypid());
    while (true) {
        usleep(10000);
    }
}
while (!is_file('worker.pid')) {
    usleep(1000);
}
file_put_contents('ready', 'ready');
if ($mode === 'normal') {
    exit(7);
}
if ($mode === 'crash') {
    posix_kill(getmypid(), SIGKILL);
}
while (true) {
    usleep(10000);
}
PHP);
        chmod($root . '/bin/redis-client-tester-harness', 0o700);
        $env = getenv();
        unset($env['FAILOVER']);
        $env['TEST_MODE'] = $mode;
        $process = proc_open(['bash', $root . '/local-scripts/' . $script], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $root . '/output', 'w'],
            2 => ['file', $root . '/error', 'w'],
        ], $pipes, '/', $env);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($root . '/ready') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($root . '/ready');
            if ($mode === 'interrupt') {
                proc_terminate($process, SIGINT);
                while (!is_file($root . '/interrupts') && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertSame('1', file_get_contents($root . '/interrupts'));
                proc_terminate($process, SIGINT);
            } elseif ($mode === 'terminate') {
                proc_terminate($process, SIGTERM);
            }
            do {
                $status = proc_get_status($process);
                usleep(10000);
            } while ($status['running'] && microtime(true) < $deadline);
            self::assertFalse($status['running'], (string) file_get_contents($root . '/error'));
            self::assertSame($expected, $status['exitcode']);
            $pid = (int) file_get_contents($root . '/worker.pid');
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            self::assertTrue($stat === false || str_contains($stat, ') Z '), 'worker still running: ' . $pid);
        } finally {
            proc_terminate($process, SIGTERM);
            if (is_file($root . '/worker.pid')) {
                posix_kill((int) file_get_contents($root . '/worker.pid'), SIGKILL);
            }
            proc_close($process);
            Fs::removeTree($root);
        }
    }
}
