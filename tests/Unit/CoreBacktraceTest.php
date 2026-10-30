<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\CoreBacktrace;
use Mgrunder\RedisClientTester\Harness\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreBacktraceTest extends TestCase
{
    private string $directory;
    private string|false $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gdb-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
        $this->path = getenv('PATH');
        file_put_contents($this->directory . '/gdb', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/core-gdb.php') . ' "$@"' . "\n");
        chmod($this->directory . '/gdb', 0o700);
        putenv('PATH=' . $this->directory);
    }

    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
        putenv('RCT_GDB_TEST_MODE');
        Fs::removeTree($this->directory);
    }

    public function testFullBacktraceStreamsDiagnosticsAndUsesExactExecutableAndCore(): void
    {
        $core = $this->directory . '/core.worker 42';
        file_put_contents($core, 'retained core');
        putenv('RCT_GDB_TEST_MODE=slow');
        $capture = CoreBacktrace::start($core, '/other/php build/php');
        self::assertNotNull($capture);
        self::assertFalse($capture->poll(), 'starting gdb must not block SAPI supervision');
        $beats = 0;
        $capture->finish(static function () use (&$beats): void { $beats++; });
        self::assertGreaterThan(0, $beats);
        self::assertTrue($capture->poll());
        $path = CoreBacktrace::path($core);
        $output = (string) file_get_contents($path);
        foreach (['thread apply all bt full', 'set auto-load off', 'set debuginfod enabled off',
            '--se=/other/php build/php', 'fixture gdb diagnostic', 'Thread 2', 'Thread 1',
            'local_value = 42', 'local_value = 99', '[gdb: exit 0]'] as $expected) {
            self::assertStringContainsString($expected, str_replace('\\/', '/', $output));
        }
        self::assertStringContainsString('--core=' . $core, str_replace('\\/', '/', $output));
        self::assertSame('retained core', file_get_contents($core));
        self::assertNull(CoreBacktrace::start($core, PHP_BINARY));
        self::assertSame($output, file_get_contents($path));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'gdb failure' => ['fail', '[gdb: exit 7]'];
        yield 'missing gdb' => ['missing', 'cannot execute gdb'];
        yield 'timeout' => ['timeout', 'timed out after 120 seconds'];
        yield 'interrupted' => ['interrupted', 'interrupted; partial output retained'];
    }

    #[DataProvider('failures')]
    public function testFailuresRetainDiagnosticsAndNeverModifyTheCore(string $mode, string $expected): void
    {
        $core = $this->directory . '/core.123';
        file_put_contents($core, 'retained core');
        if ($mode === 'missing') {
            unlink($this->directory . '/gdb');
        }
        putenv('RCT_GDB_TEST_MODE=' . ($mode === 'fail' ? 'fail' : 'hang'));
        $capture = CoreBacktrace::start($core, PHP_BINARY);
        if ($mode === 'missing' && $capture === null) {
            self::assertStringContainsString('cannot start gdb', (string) file_get_contents(CoreBacktrace::path($core)));
            self::assertSame('retained core', file_get_contents($core));
            return;
        }
        self::assertNotNull($capture);
        if ($mode === 'timeout' || $mode === 'interrupted') {
            $deadline = microtime(true) + 5;
            while (!str_contains((string) file_get_contents(CoreBacktrace::path($core)), 'fixture gdb diagnostic')
                && microtime(true) < $deadline) {
                usleep(10000);
            }
        }
        if ($mode === 'timeout') {
            (new \ReflectionProperty($capture, 'deadline'))->setValue($capture, 0.0);
        }
        if ($mode === 'interrupted') {
            try {
                $capture->finish(static function (): void { throw new \RuntimeException('stop capture'); });
                self::fail('expected interruption');
            } catch (\RuntimeException $error) {
                self::assertSame('stop capture', $error->getMessage());
            }
        } else {
            $capture->finish();
        }
        self::assertTrue($capture->poll());
        self::assertStringContainsString($expected, (string) file_get_contents(CoreBacktrace::path($core)));
        self::assertSame('retained core', file_get_contents($core));
    }
}
