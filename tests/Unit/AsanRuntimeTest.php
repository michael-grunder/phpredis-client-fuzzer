<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\AsanRuntime;
use PHPUnit\Framework\TestCase;

final class AsanRuntimeTest extends TestCase
{
    public function testEnvironmentIsAppliedToChildAndOverridesKeepReportsOnStderr(): void
    {
        $previous = getenv('ASAN_OPTIONS');
        putenv('ASAN_OPTIONS=detect_leaks=1:log_path=/tmp/lost-report');
        try {
            // A readable stand-in: this test builds argv without loading a DSO.
            $runtime = AsanRuntime::create(__FILE__);
            $argv = $runtime->command(['/usr/bin/php', '-n', 'script with spaces.php']);
            self::assertSame('/usr/bin/env', $argv[0]);
            self::assertContains('USE_ZEND_ALLOC=0', $argv);
            self::assertContains('USE_TRACKED_ALLOC=1', $argv);
            self::assertContains('ZEND_DONT_UNLOAD_MODULES=1', $argv);
            self::assertSame(['/usr/bin/php', '-n', 'script with spaces.php'], array_slice($argv, -3));
            self::assertStringContainsString('detect_leaks=1', $runtime->environment['ASAN_OPTIONS']);
            self::assertStringEndsWith(':halt_on_error=1:log_path=stderr', $runtime->environment['ASAN_OPTIONS']);
            self::assertSame('detect_leaks=1:log_path=/tmp/lost-report', getenv('ASAN_OPTIONS'));
        } finally {
            putenv($previous === false ? 'ASAN_OPTIONS' : 'ASAN_OPTIONS=' . $previous);
        }
    }

    public function testMissingRuntimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASAN runtime not found');
        AsanRuntime::create('/nonexistent/libasan.so');
    }

    public function testValgrindCannotBeTheInferior(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without Valgrind or rr');
        AsanRuntime::create(__FILE__)->command(['/usr/bin/valgrind', 'php']);
    }
}
