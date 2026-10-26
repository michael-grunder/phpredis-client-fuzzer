<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\MemoryReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarnessMemoryReportTest extends TestCase
{
    #[DataProvider('diagnostics')]
    public function testRecognizesOnlyAllocationFatalDiagnostics(string $text, bool $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'memory-report-');
        self::assertIsString($path);
        try {
            file_put_contents($path, $text);
            $report = MemoryReport::scan($path . '.missing', $path);
            self::assertSame($expected, $report !== null);
            if ($report !== null) {
                self::assertNotSame('', $report->message);
            }
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function diagnostics(): iterable
    {
        yield 'reported failure' => ["  Fatal error: Allowed memory size of 17179869184 bytes exhausted (tried to allocate 34359738392 bytes) in /src/CoerciveClientInvoker.php on line 19\nStack trace:\n#0 Relay\\Cluster->setbit()\n", true];
        yield 'debug allocation site' => ['PHP Fatal error: Allowed memory size of 33554432 bytes exhausted at /Zend/zend_string.h:123 (tried to allocate 67108864 bytes) in child.php on line 1', true];
        yield 'logged overflow' => ['[14-Sep-2026 12:00:00 UTC] PHP Fatal error: Possible integer overflow in memory allocation (9223372036854775807 * 8 + 32) in child.php on line 1', true];
        yield 'potential overflow' => ['Fatal error: Potential integer overflow in memory allocation (1 + 18446744073709551615)', true];
        yield 'allocator failure' => ['PHP Fatal error: Out of memory (allocated 1048576 bytes) (tried to allocate 34359738392 bytes)', true];
        yield 'allocator fallback' => ["Out of memory\n", true];
        yield 'html display' => ['<br /><b>Fatal error</b>: Allowed memory size of 33554432 bytes exhausted (tried to allocate 67108864 bytes) in <b>child.php</b> on line <b>1</b><br />', true];
        yield 'long preceding log line' => [str_repeat('x', 40000) . "\nPHP Fatal error: Possible integer overflow in memory allocation (1 + 18446744073709551615)\n", true];
        yield 'empty' => ['', false];
        yield 'ordinary fatal' => ['PHP Fatal error: Uncaught Error: Call to undefined method Redis::foo()', false];
        yield 'redis error' => ['OOM command not allowed when used memory > maxmemory.', false];
        yield 'warning' => ['PHP Warning: Out of memory', false];
        yield 'quoted argument' => ['#0 Redis->set("key", "Fatal error: Allowed memory size of 32 bytes exhausted")', false];
        yield 'chunk boundary inside argument' => [str_repeat('x', 16383) . "Fatal error: Out of memory\n", false];
        yield 'leak' => ['=== Total 2 memory leaks detected ===', false];
    }
}
