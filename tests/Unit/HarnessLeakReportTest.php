<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\LeakReport;
use PHPUnit\Framework\TestCase;

final class HarnessLeakReportTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testParsesADebugBuildLeakReport(): void
    {
        $stderr = <<<'LOG'
        [Tue Sep  1 14:58:24 2026]  Script:  '/home/mike/dev/phpfarm/src/php-8.5.0-debug/r.php'
        /home/mike/dev/phpfarm/src/php-8.5.0-debug/ext/relay/src/relay.c(5629) :  Freeing 0x00007f4a30a94500 (1024 bytes), script=/home/mike/dev/phpfarm/src/php-8.5.0-debug/r.php
        Last leak repeated 1 time
        === Total 2 memory leaks detected ===
        LOG;

        $report = LeakReport::scan($this->write($stderr));

        self::assertNotNull($report);
        self::assertSame(2, $report->count);
        self::assertSame(1024, $report->bytes);
        self::assertNotNull($report->firstSite);
        self::assertStringContainsString('relay.c(5629)', $report->firstSite);
    }

    public function testSumsEveryFreeingLine(): void
    {
        $stderr = <<<'LOG'
        relay.c(10) :  Freeing 0x00007f0000000000 (64 bytes), script=r.php
        relay.c(20) :  Freeing 0x00007f0000000040 (128 bytes), script=r.php
        === Total 2 memory leaks detected ===
        LOG;

        $report = LeakReport::scan($this->write($stderr));

        self::assertNotNull($report);
        self::assertSame(192, $report->bytes);
        self::assertSame(LeakReport::SOURCE_ZEND_MM, $report->source);
    }

    public function testParsesARelaySharedAllocatorLeakReport(): void
    {
        $stderr = <<<'LOG'
        relay.c:5636 leaked block of 112 bytes at 0x7fe161617810 allocated by pid 3844901 at 1788310743.315590 (leak repeated 2 times)
        relay.c:5640 leaked block of 64 bytes at 0x7fe161617900 allocated by pid 3844901 at 1788310743.315912
        LOG;

        $report = LeakReport::scan($this->write($stderr));

        self::assertNotNull($report);
        self::assertSame(LeakReport::SOURCE_RELAY_SHM, $report->source);
        self::assertSame(3, $report->count);
        self::assertSame(112 * 2 + 64, $report->bytes);
        self::assertSame('relay.c:5636', $report->firstSite);
    }

    public function testPrefersTheZendReportWhenBothArePresent(): void
    {
        $stderr = <<<'LOG'
        relay.c:5636 leaked block of 112 bytes at 0x7fe161617810 allocated by pid 42 at 1788310743.315590
        relay.c(5629) :  Freeing 0x00007f4a30a94500 (1024 bytes), script=r.php
        === Total 1 memory leak detected ===
        LOG;

        $report = LeakReport::scan($this->write($stderr));

        self::assertNotNull($report);
        self::assertSame(LeakReport::SOURCE_ZEND_MM, $report->source);
    }

    public function testReturnsNullWithoutASummaryLine(): void
    {
        self::assertNull(LeakReport::scan($this->write("just some warnings\nnothing to see here\n")));
    }

    public function testReturnsNullForAMissingFile(): void
    {
        self::assertNull(LeakReport::scan(sys_get_temp_dir() . '/definitely-not-here-' . uniqid()));
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'leakrpt');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
