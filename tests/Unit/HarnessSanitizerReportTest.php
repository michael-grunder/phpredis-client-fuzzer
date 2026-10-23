<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\SanitizerReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarnessSanitizerReportTest extends TestCase
{
    #[DataProvider('diagnostics')]
    public function testRecognizesSanitizerDiagnostics(string $text, bool $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'memory-report-');
        self::assertIsString($path);
        try {
            file_put_contents($path, $text);
            $report = SanitizerReport::scan($path);
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
        yield 'asan' => ["==123==ERROR: AddressSanitizer: heap-use-after-free on address 0x123\n", true];
        yield 'lsan' => ["==123==ERROR: LeakSanitizer: detected memory leaks\n", true];
        yield 'ubsan' => ["/src/redis.c:123:45: runtime error: signed integer overflow\n", true];
        yield 'ubsan summary' => ["SUMMARY: UndefinedBehaviorSanitizer: undefined-behavior /src/relay.c:1:2\n", true];
        yield 'deadly signal' => ["AddressSanitizer:DEADLYSIGNAL\n", true];
        yield 'long preceding line' => [str_repeat('x', 40000) . "\n==123==ERROR: AddressSanitizer: stack-buffer-overflow\n", true];
        yield 'empty' => ['', false];
        yield 'php warning' => ['PHP Warning: AddressSanitizer: heap-use-after-free', false];
        yield 'quoted argument' => ['#0 Redis->set("key", "ERROR: AddressSanitizer: heap-use-after-free")', false];
        yield 'chunk boundary in argument' => [str_repeat('x', 16383) . "ERROR: AddressSanitizer: heap-use-after-free\n", false];
        yield 'startup runtime warning' => ['==12==ASan runtime does not come first in initial library list', false];
    }
}
