<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Sapi\ClientMonitor;
use PHPUnit\Framework\TestCase;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;

final class SapiClientMonitorTest extends TestCase
{
    public function testDashboardShowsClientProgressWithBoundedHistoryAndResizes(): void
    {
        $monitor = new ClientMonitor('a1b2c3d4e5f6', 42, 20, '1-4');
        for ($number = 1; $number <= 12; $number++) {
            $monitor->started($number, 100 + $number, 'clients/example/request-' . $number . '.php');
            if ($number < 12) {
                $monitor->completed($number, match ($number) { 10 => 1, 11 => 78, default => 0 });
            }
        }
        $backend = new DummyBackend(120, 22);
        (new \ReflectionProperty($monitor, 'display'))->setValue($monitor, DisplayBuilder::default($backend)->build());
        $monitor->render(3);
        $screen = $backend->toString();
        foreach (['sapi client', 'a1b2c3d4e5f6', '42', '12/20', 'RUN', 'OK', 'FAIL', 'STARTUP', '3 (configured 1-4)'] as $text) {
            self::assertStringContainsString($text, $screen);
        }
        self::assertMatchesRegularExpression('/Completed\s+11/', $screen);
        self::assertMatchesRegularExpression('/In flight\s+1\s+.*Failed\s+2/', $screen);
        self::assertMatchesRegularExpression('/request-12\.php.*request-11\.php.*request-10\.php/s', $screen);
        self::assertStringContainsString('request-2.php', $screen);
        self::assertStringNotContainsString('request-1.php', $screen);

        $small = new DummyBackend(60, 10);
        (new \ReflectionProperty($monitor, 'display'))->setValue($monitor, DisplayBuilder::default($small)->build());
        (new \ReflectionProperty($monitor, 'lastRender'))->setValue($monitor, 0.0);
        $monitor->render(3);
        self::assertStringContainsString('sapi client', $small->toString());
        self::assertCount(10, $small->toLines());
        foreach ($small->toLines() as $line) {
            self::assertSame(60, mb_strlen($line));
        }
        $monitor->stop();
        $monitor->stop();
    }

    public function testUnlimitedClientInitiallyHasNoRequests(): void
    {
        $monitor = new ClientMonitor('server', 42, 0, '1');
        $backend = new DummyBackend(100, 12);
        (new \ReflectionProperty($monitor, 'display'))->setValue($monitor, DisplayBuilder::default($backend)->build());
        $monitor->render(1);
        self::assertStringContainsString('0/unlimited', $backend->toString());
        self::assertMatchesRegularExpression('/In flight\s+0/', $backend->toString());
    }
}
