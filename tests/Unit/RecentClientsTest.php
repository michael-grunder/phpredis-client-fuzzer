<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\RecentClients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;

final class RecentClientsTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function lists(): iterable
    {
        yield 'method' => [[['id' => '18446744073709551615', 'addr' => '[::1]:12345',
            'laddr' => '[::1]:6379', 'user' => 'default', 'name' => 'worker']]];
        yield 'raw' => ["id=18446744073709551615 addr=[::1]:12345 laddr=[::1]:6379 user=default name=worker\n"];
    }

    #[DataProvider('lists')]
    public function testParsesBothSurfacesWithoutTruncatingIds(mixed $reply): void
    {
        $cache = new RecentClients();
        $client = new Redis();
        $cache->observe($client, 'route', $reply);
        self::assertSame(['record' => ['id' => '18446744073709551615', 'addr' => '[::1]:12345',
            'user' => 'default', 'laddr' => '[::1]:6379'], 'age' => 0], $cache->sample($client, 'route'));
    }

    public function testSeparatesClientsRoutesAndRunConfigurations(): void
    {
        $config = new FuzzConfig();
        $client = new Redis();
        $other = new Redis();
        $config->recentClients()->observe($client, 'a', [['id' => 12]]);
        self::assertNull($config->recentClients()->sample($client, 'b'));
        self::assertNull($config->recentClients()->sample($other, 'a'));
        self::assertNull((new FuzzConfig())->recentClients()->sample($client, 'a'));
        self::assertSame('12', $config->recentClients()->sample($client, 'a')['record']['id'] ?? null);
    }

    public function testExpiresAfterOtherCommandsAdvanceTheRun(): void
    {
        $config = new FuzzConfig();
        $client = new Redis();
        $config->recentClients()->observe($client, '', [['id' => 12]]);
        for ($step = 0; $step < 255; $step++) {
            $config->beginStep(false);
        }
        self::assertSame(255, $config->recentClients()->sample($client, '')['age'] ?? null);
        $config->beginStep(false);
        self::assertNull($config->recentClients()->sample($client, ''));
    }

    public function testQueuedErrorsAndMalformedRepliesDoNotRefreshOrReplaceObservations(): void
    {
        $cache = new RecentClients();
        $client = new Redis();
        $cache->observe($client, '', [['id' => 12]]);
        $cache->advanceStep();
        foreach ([false, 'QUEUED', $client, [['id' => -1]], [['id' => '1.5']],
            [['addr' => 123]], [['id' => '0']], [['addr' => "bad\0address"]],
            "id=oops laddr=127.0.0.1:6379\n"] as $reply) {
            $cache->observe($client, '', $reply);
            self::assertSame(['record' => ['id' => '12'], 'age' => 1], $cache->sample($client, ''));
        }
    }

    public function testSuccessfulListsReplaceAndEmptyListsClear(): void
    {
        $cache = new RecentClients();
        $client = new Redis();
        foreach ([[], ''] as $empty) {
            $cache->observe($client, '', [['id' => 1], ['id' => 2]]);
            $cache->observe($client, '', [['id' => 3]]);
            self::assertSame('3', $cache->sample($client, '')['record']['id'] ?? null);
            $cache->observe($client, '', $empty);
            self::assertNull($cache->sample($client, ''));
        }
    }

    public function testBoundsRetainedRoutesAndRecords(): void
    {
        $cache = new RecentClients();
        $client = new Redis();
        $records = [];
        for ($id = 1; $id <= 100; $id++) {
            $records[] = ['id' => $id];
        }
        for ($route = 0; $route < 17; $route++) {
            $cache->observe($client, 'route-' . $route, $records);
        }
        self::assertNull($cache->sample($client, 'route-0'));
        mt_srand(42);
        for ($sample = 0; $sample < 300; $sample++) {
            $id = $cache->sample($client, 'route-16')['record']['id'] ?? null;
            self::assertNotNull($id);
            self::assertGreaterThanOrEqual(1, (int) $id);
            self::assertLessThanOrEqual(64, (int) $id);
        }
    }
}
