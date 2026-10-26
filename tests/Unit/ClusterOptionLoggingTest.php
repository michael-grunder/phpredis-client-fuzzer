<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\ScriptLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;

final class ClusterOptionLoggingTest extends TestCase
{
    /** @return iterable<string, array{class-string<RedisCluster|Cluster>, int|false}> */
    public static function clients(): iterable
    {
        yield 'PhpRedis' => [RedisCluster::class, RedisCluster::FAILOVER_DISTRIBUTE_SLAVES];
        yield 'Relay legacy' => [Cluster::class, RedisCluster::FAILOVER_ERROR];
        yield 'Relay modern' => [Cluster::class, false];
    }

    /** @param class-string<RedisCluster|Cluster> $class */
    #[DataProvider('clients')]
    public function testReproductionPreservesClusterOptions(string $class, int|false $legacy): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Client extension is not loaded');
        }

        $client = $this->createStub($class);
        $client->method('_masters')->willReturn([]);
        $client->method('getOption')->willReturnCallback(static function (int $option) use ($legacy): mixed {
            if ($option === RedisCluster::OPT_SLAVE_FAILOVER) {
                return $legacy;
            }
            if ($option === Redis::OPT_PREFIX) {
                return '';
            }

            return 0;
        });

        $path = tempnam(sys_get_temp_dir(), 'cluster-option-log-');
        self::assertIsString($path);
        try {
            ScriptLogger::init($path, clients: [$client]);
            ScriptLogger::finish();
            $script = file_get_contents($path);
            self::assertIsString($script);

            if ($legacy === false) {
                self::assertStringNotContainsString('::OPT_SLAVE_FAILOVER,', $script);
            } else {
                self::assertStringContainsString("setOption({$class}::OPT_SLAVE_FAILOVER, {$legacy});", $script);
            }
            if ($class === Cluster::class) {
                foreach (['OPT_FAILOVER', 'OPT_DISTRIBUTE'] as $option) {
                    if (defined($class . '::' . $option)) {
                        self::assertStringContainsString("setOption({$class}::{$option}, 0);", $script);
                    }
                }
            }
        } finally {
            ScriptLogger::finish();
            unlink($path);
        }
    }
}
