<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\CacheSaturator;
use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class CacheSaturatorTest extends TestCase
{
    protected function setUp(): void
    {
        Command::resetCapturedWarnings();
    }

    protected function tearDown(): void
    {
        Command::finishCapturedWarnings();
    }

    public function testBoundedBatchesResumeAcrossTheKnownKeySpace(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            (new FuzzConfig())->setKeys(2)->setShards(2),
            $invoker,
        );
        $client = new Relay();

        $first = $saturator->run($client, 3, 1, 4, null, null);
        $second = $saturator->run($client, 3, 2, 3, null, null);

        self::assertSame(10, $saturator->keySpaceSize());
        self::assertCount(4, $first['outcomes']);
        self::assertCount(3, $second['outcomes']);
        self::assertSame([
            ['get', ['string:0']],
            ['lrange', ['list:0', 0, -1]],
            ['smembers', ['set:0']],
            ['hgetall', ['hash:0']],
            ['zrange', ['zset:0', 0, -1, true]],
            ['get', ['string:1']],
            ['lrange', ['list:1', 0, -1]],
        ], $invoker->calls);
        self::assertSame('string:0', $first['outcomes'][0]->variant);
        self::assertSame('saturate:get', $first['outcomes'][0]->command);
        self::assertSame('saturation', $first['outcomes'][0]->operation);
        self::assertSame('Relay\\Relay#3', $first['outcomes'][0]->clientId);
    }

    public function testClusterPassIncludesEveryConfiguredShard(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            (new FuzzConfig())->setCluster(true)->setKeys(1)->setShards(2),
            $invoker,
        );

        $batch = $saturator->run(new Relay(), 0, 1, null, null, null);

        self::assertCount(10, $batch['outcomes']);
        self::assertSame(['get', ['string:{0}:0']], $invoker->calls[0]);
        self::assertSame(['get', ['string:{1}:0']], $invoker->calls[5]);
    }

    public function testNonAtomicClientsAreNotGivenSaturationReads(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(new FuzzConfig(), $invoker);
        $client = new class extends Relay {
            public function getMode(bool $masked = false): int
            {
                return Redis::PIPELINE;
            }
        };

        $batch = $saturator->run($client, 0, 1, 10, null, null);

        self::assertSame([], $batch['outcomes']);
        self::assertSame([], $invoker->calls);
    }

    public function testTargetOverridesTheStepLimitAndStopsAtUsedMemory(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $usage = [0, 10, 50];
        $sample = 0;
        $saturator = new CacheSaturator(
            (new FuzzConfig())->setKeys(2),
            $invoker,
            static function () use (&$usage, &$sample): int {
                return $usage[$sample++];
            },
        );

        $batch = $saturator->run(new Relay(), 0, 1, 1, null, null, 50);

        self::assertCount(2, $batch['outcomes']);
        self::assertSame([
            ['get', ['string:0']],
            ['lrange', ['list:0', 0, -1]],
        ], $invoker->calls);
        self::assertSame(3, $sample);
    }

    public function testTargetStopsAfterOneKeySpacePassWhenItCannotBeReached(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            (new FuzzConfig())->setKeys(1),
            $invoker,
            static fn (): int => 0,
        );

        $batch = $saturator->run(new Relay(), 0, 1, 1, null, null, 100);

        self::assertCount($saturator->keySpaceSize(), $batch['outcomes']);
        self::assertCount($saturator->keySpaceSize(), $invoker->calls);
    }

    public function testTargetAlreadyReachedPerformsNoReads(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            new FuzzConfig(),
            $invoker,
            static fn (): int => 100,
        );

        $batch = $saturator->run(new Relay(), 0, 1, null, null, null, 100);

        self::assertSame([], $batch['outcomes']);
        self::assertSame([], $invoker->calls);
    }
}

final class RecordingSaturationInvoker implements ClientInvoker
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    public function invoke(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
    ): mixed {
        $this->calls[] = [$method, $arguments];

        return $method;
    }
}
