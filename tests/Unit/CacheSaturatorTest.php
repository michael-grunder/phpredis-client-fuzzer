<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\CacheSaturator;
use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\SaturationMode;
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

    public function testSeededModeWritesEachGeneratedTypeBeforeReadingIt(): void
    {
        mt_srand(20260830);
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            (new FuzzConfig())
                ->setKeys(1)
                ->setMembers(2)
                ->setMinLen(4)
                ->setMaxLen(4),
            $invoker,
        );

        $batch = $saturator->run(
            new Relay(),
            0,
            1,
            5,
            null,
            null,
            mode: SaturationMode::Seeded,
        );

        self::assertCount(5, $batch['outcomes']);
        self::assertSame('saturate:seeded:get', $batch['outcomes'][0]->command);
        self::assertSame([
            'set', 'get',
            'rpush', 'lrange',
            'sadd', 'smembers',
            'hset', 'hgetall',
            'zadd', 'zrange',
        ], array_column($invoker->calls, 0));
        self::assertSame('string:0', $invoker->calls[0][1][0]);
        self::assertIsString($invoker->calls[0][1][1]);
        self::assertSame('list:0', $invoker->calls[2][1][0]);
        self::assertGreaterThanOrEqual(2, count($invoker->calls[2][1]));
        self::assertSame('set:0', $invoker->calls[4][1][0]);
        self::assertGreaterThanOrEqual(2, count($invoker->calls[4][1]));
        self::assertSame('hash:0', $invoker->calls[6][1][0]);
        self::assertIsArray($invoker->calls[6][1][1]);
        self::assertNotEmpty($invoker->calls[6][1][1]);
        self::assertSame('zset:0', $invoker->calls[8][1][0]);
        self::assertGreaterThanOrEqual(3, count($invoker->calls[8][1]));
    }

    public function testFastModeSplitsTheTargetIntoOneBitmapPerStep(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            (new FuzzConfig())->setKeys(2),
            $invoker,
            static fn (): int => 0,
        );

        $batch = $saturator->run(
            new Relay(),
            0,
            1,
            4,
            null,
            null,
            1000,
            SaturationMode::Fast,
        );

        // 1000 bytes over four steps is 250 bytes per key, and the last bit of
        // a 250 byte string is offset 1999.
        self::assertSame([
            ['setbit', ['saturate:250:0', 1999, true]],
            ['get', ['saturate:250:0']],
            ['setbit', ['saturate:250:1', 1999, true]],
            ['get', ['saturate:250:1']],
            ['setbit', ['saturate:250:2', 1999, true]],
            ['get', ['saturate:250:2']],
            ['setbit', ['saturate:250:3', 1999, true]],
            ['get', ['saturate:250:3']],
        ], $invoker->calls);
        self::assertCount(4, $batch['outcomes']);
        self::assertSame('saturate:fast:get', $batch['outcomes'][0]->command);
        self::assertSame('saturate:250:0', $batch['outcomes'][0]->variant);
        self::assertSame('saturation', $batch['outcomes'][0]->operation);
        self::assertNull($batch['outcomes'][0]->exception);
    }

    public function testFastModeGranularityFollowsTheStepCount(): void
    {
        $coarse = self::fastCalls(10, 10485760);
        $fine = self::fastCalls(100, 10485760);

        self::assertCount(20, $coarse);
        self::assertCount(200, $fine);
        // Every event restarts at the first key so the server-side key space
        // stays bounded across refills.
        self::assertSame(['setbit', ['saturate:1048576:0', 1048576 * 8 - 1, true]], $coarse[0]);
        self::assertSame(['setbit', ['saturate:104858:0', 104858 * 8 - 1, true]], $fine[0]);
    }

    /** @return list<array{string, list<mixed>}> */
    private static function fastCalls(int $steps, int $target): array
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            new FuzzConfig(),
            $invoker,
            static fn (): int => 0,
        );
        $saturator->run(new Relay(), 0, 1, $steps, null, null, $target, SaturationMode::Fast);

        return $invoker->calls;
    }

    public function testFastModeRoundsThePartialChunkUp(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            new FuzzConfig(),
            $invoker,
            static fn (): int => 0,
        );

        $saturator->run(new Relay(), 0, 1, 3, null, null, 100, SaturationMode::Fast);

        self::assertCount(6, $invoker->calls);
        self::assertSame(['setbit', ['saturate:34:0', 34 * 8 - 1, true]], $invoker->calls[0]);
    }

    public function testFastModeStopsOnceTheTargetIsReached(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $usage = [0, 400, 1100];
        $sample = 0;
        $saturator = new CacheSaturator(
            new FuzzConfig(),
            $invoker,
            static function () use (&$usage, &$sample): int {
                return $usage[$sample++];
            },
        );

        $batch = $saturator->run(
            new Relay(),
            0,
            1,
            8,
            null,
            null,
            1000,
            SaturationMode::Fast,
        );

        self::assertCount(2, $batch['outcomes']);
        self::assertSame(['setbit', 'get', 'setbit', 'get'], array_column($invoker->calls, 0));
        self::assertSame(3, $sample);
    }

    public function testFastModeSpreadsClusterKeysAcrossHashTags(): void
    {
        $invoker = new RecordingSaturationInvoker();
        $saturator = new CacheSaturator(
            (new FuzzConfig())->setCluster(true)->setShards(2),
            $invoker,
            static fn (): int => 0,
        );

        $saturator->run(new Relay(), 0, 1, 3, null, null, 300, SaturationMode::Fast);

        $keys = array_map(
            static fn (array $call): mixed => $call[1][0],
            $invoker->calls,
        );

        self::assertSame([
            'saturate:{0}:100:0',
            'saturate:{0}:100:0',
            'saturate:{1}:100:1',
            'saturate:{1}:100:1',
            'saturate:{0}:100:2',
            'saturate:{0}:100:2',
        ], $keys);
    }

    public function testFastModeRequiresAByteTarget(): void
    {
        $saturator = new CacheSaturator(
            new FuzzConfig(),
            new RecordingSaturationInvoker(),
            static fn (): int => 0,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fast saturation requires a byte target');

        $saturator->run(new Relay(), 0, 1, 4, null, null, null, SaturationMode::Fast);
    }

    public function testFastModeStillReadsWhenTheBitmapWriteThrows(): void
    {
        $invoker = new RecordingSaturationInvoker('setbit');
        $saturator = new CacheSaturator(
            new FuzzConfig(),
            $invoker,
            static fn (): int => 0,
        );

        $batch = $saturator->run(
            new Relay(),
            0,
            1,
            1,
            null,
            null,
            1024,
            SaturationMode::Fast,
        );

        self::assertSame(['setbit', 'get'], array_column($invoker->calls, 0));
        self::assertSame(
            'Synthetic setbit failure',
            $batch['outcomes'][0]->exception['message'] ?? null,
        );
        self::assertSame('string', $batch['outcomes'][0]->replyType);
    }

    public function testSeededModeStillAttemptsTheReadWhenTheWriteThrows(): void
    {
        $invoker = new RecordingSaturationInvoker('set');
        $saturator = new CacheSaturator((new FuzzConfig())->setKeys(1), $invoker);

        $batch = $saturator->run(
            new Relay(),
            0,
            1,
            1,
            null,
            null,
            mode: SaturationMode::Seeded,
        );

        self::assertSame(['set', 'get'], array_column($invoker->calls, 0));
        self::assertSame('Synthetic set failure', $batch['outcomes'][0]->exception['message'] ?? null);
        self::assertSame('string', $batch['outcomes'][0]->replyType);
    }
}

final class RecordingSaturationInvoker implements ClientInvoker
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    public function __construct(private readonly ?string $throwOn = null)
    {
    }

    public function invoke(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
    ): mixed {
        $this->calls[] = [$method, $arguments];
        if ($method === $this->throwOn) {
            throw new \RuntimeException('Synthetic ' . $method . ' failure');
        }

        return $method;
    }
}
