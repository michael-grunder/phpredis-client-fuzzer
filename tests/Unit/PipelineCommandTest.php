<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Commands\Command\pipeline;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

trait RecordsPipelineEntry
{
    public int $mode = Redis::ATOMIC;
    public int $entries = 0;

    public function __construct()
    {
    }

    public function getMode(bool $flags = false): int
    {
        return $this->mode;
    }

    public function pipeline(): static|false
    {
        $this->entries++;
        $this->mode = Redis::PIPELINE;

        return $this;
    }

    public function getLastError(): ?string
    {
        return null;
    }
}

final class PipelineCommandTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function clients(): iterable
    {
        foreach ([Redis::class, RedisCluster::class, Relay::class, Cluster::class] as $class) {
            yield $class => [$class];
        }
    }

    /** @param class-string $class */
    #[DataProvider('clients')]
    public function testPipelineUsesCapabilityAndRequiresAtomicMode(string $class): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped($class . ' is not loaded');
        }

        $client = match ($class) {
            Redis::class => new class extends Redis {
                use RecordsPipelineEntry;
            },
            RedisCluster::class => new class extends RedisCluster {
                use RecordsPipelineEntry;
            },
            Relay::class => new class extends Relay {
                use RecordsPipelineEntry;
            },
            Cluster::class => new class extends Cluster {
                use RecordsPipelineEntry;
            },
            default => self::fail('Unknown client ' . $class),
        };
        $command = new pipeline();
        $config = new FuzzConfig();

        self::assertSame($client, $command->fuzz($client, $config));
        self::assertSame(Redis::PIPELINE, $client->mode);
        self::assertSame(1, $client->entries);

        foreach ([Redis::PIPELINE, Redis::MULTI] as $mode) {
            $client->mode = $mode;
            self::assertFalse($command->fuzz($client, $config));
            self::assertSame($mode, $client->mode);
            self::assertSame(1, $client->entries);
        }
    }

    public function testClusterWithoutPipelineIsSkipped(): void
    {
        if (method_exists(RedisCluster::class, 'pipeline')) {
            self::markTestSkipped('This PhpRedis supports cluster pipelines');
        }

        $client = new class extends RedisCluster {
            public function __construct()
            {
            }

            public function getMode(): int
            {
                throw new \Error('Unsupported client was probed');
            }
        };

        self::assertFalse((new pipeline())->fuzz($client, new FuzzConfig()));
    }
}
