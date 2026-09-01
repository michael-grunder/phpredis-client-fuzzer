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
