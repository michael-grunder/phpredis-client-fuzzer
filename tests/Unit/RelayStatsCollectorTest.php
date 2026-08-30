<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\RelayStatsCollector;
use PHPUnit\Framework\TestCase;

final class RelayStatsCollectorTest extends TestCase
{
    public function testItReportsLatestCountersAndMemoryWithObservedPeaks(): void
    {
        $snapshots = [
            self::snapshot(1, 2, 0, 4096, 3072, 1000, 800),
            self::snapshot(4, 5, 1, 4096, 3072, 2400, 1800),
            self::snapshot(9, 7, 2, 4096, 3072, 1200, 900),
        ];
        $index = 0;
        $collector = new RelayStatsCollector(
            static function () use (&$snapshots, &$index): array {
                return $snapshots[$index++];
            },
        );

        $collector->sample();
        $collector->sample();
        $collector->sample();

        self::assertSame([
            'samples' => 3,
            'hits' => 9,
            'misses' => 7,
            'oom' => 2,
            'memory' => [
                'total' => 4096,
                'limit' => 3072,
                'active' => 1200,
                'used' => 900,
                'peak_active' => 2400,
                'peak_used' => 1800,
            ],
        ], $collector->statistics());
    }

    public function testItHasNoStatisticsBeforeTheFirstSample(): void
    {
        $collector = new RelayStatsCollector(static fn (): array => self::snapshot(0, 0, 0, 1, 1, 0, 0));

        self::assertNull($collector->statistics());
    }

    /** @return array<string, mixed> */
    private static function snapshot(
        int $hits,
        int $misses,
        int $oom,
        int $total,
        int $limit,
        int $active,
        int $used,
    ): array {
        return [
            'stats' => ['hits' => $hits, 'misses' => $misses, 'oom' => $oom],
            'memory' => [
                'total' => $total,
                'limit' => $limit,
                'active' => $active,
                'used' => $used,
            ],
        ];
    }
}
