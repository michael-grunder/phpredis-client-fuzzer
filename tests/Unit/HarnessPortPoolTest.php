<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\PortPool;
use PHPUnit\Framework\TestCase;

final class HarnessPortPoolTest extends TestCase
{
    public function testCycleWalksTheListRoundRobin(): void
    {
        $pool = new PortPool([7000, 7001, 7002], 'cycle', false);

        self::assertSame([7000, 7001, 7002, 7000], [
            $pool->acquire(),
            $pool->acquire(),
            $pool->acquire(),
            $pool->acquire(),
        ]);
    }

    public function testSharedPoolNeverBlocks(): void
    {
        $pool = new PortPool([7000], 'cycle', false);

        self::assertSame(7000, $pool->acquire());
        self::assertTrue($pool->hasFree());
        self::assertSame(7000, $pool->acquire());
    }

    public function testIsolatedPoolWithholdsBusyPorts(): void
    {
        $pool = new PortPool([7000, 7001], 'cycle', true);

        self::assertSame(7000, $pool->acquire());
        self::assertSame(7001, $pool->acquire());
        self::assertFalse($pool->hasFree());
        self::assertNull($pool->acquire());

        $pool->release(7000);
        self::assertTrue($pool->hasFree());
        self::assertSame(7000, $pool->acquire());
    }

    public function testEmptyPool(): void
    {
        $pool = new PortPool([], 'cycle', false);

        self::assertTrue($pool->isEmpty());
        self::assertFalse($pool->hasFree());
        self::assertNull($pool->acquire());
    }
}
