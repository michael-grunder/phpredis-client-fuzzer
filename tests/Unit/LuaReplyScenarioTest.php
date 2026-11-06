<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Stateful\StatefulScenarioRegistry;
use Mgrunder\RedisClientTester\Commands\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class LuaReplyScenarioTest extends TestCase
{
    protected function tearDown(): void
    {
        Command::finishCapturedWarnings();
    }

    /** @return iterable<string, array{class-string<Redis|RedisCluster|Relay|Cluster>, bool}> */
    public static function clients(): iterable
    {
        foreach ([Redis::class, RedisCluster::class, Relay::class, Cluster::class] as $class) {
            yield $class => [$class, false];
            yield $class . ' corrupted SHA reply' => [$class, true];
        }
    }

    /** @param class-string<Redis|RedisCluster|Relay|Cluster> $class */
    #[DataProvider('clients')]
    public function testShaCallsReuseTheScriptAndRoutingArguments(string $class, bool $corrupt): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Optional extension is absent');
        }
        $client = $this->createStub($class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        $loaded = [];
        $calls = [];
        $client->method('eval')->willReturnCallback(static function (
            string $script, array $arguments, int $numKeys,
        ) use (&$loaded, &$calls): mixed {
            self::assertSame(1, $numKeys);
            self::assertSame(['fuzzer:state:42:lua-replies:{0}:route', "lua-42\0binary", ''], $arguments);
            $reply = match ($script) {
                'return #KEYS' => 1,
                'return ARGV[1]' => "lua-42\0binary",
                'return {ARGV[1], {42, ARGV[2]}, #KEYS}' => ["lua-42\0binary", [42, ''], 1],
                default => throw new \LogicException('Unexpected Lua fixture'),
            };
            $loaded[sha1($script)] = [$arguments, $numKeys, $reply];
            $calls[] = 'eval';
            return $reply;
        });
        $client->method('evalsha')->willReturnCallback(static function (
            string $sha, array $arguments, int $numKeys,
        ) use (&$loaded, &$calls, $corrupt): mixed {
            self::assertArrayHasKey($sha, $loaded, 'EVALSHA must follow EVAL on the same client');
            self::assertSame($loaded[$sha][0], $arguments);
            self::assertSame($loaded[$sha][1], $numKeys);
            $calls[] = 'evalsha';
            return $corrupt ? 'incorrect reply' : $loaded[$sha][2];
        });

        $outcome = StatefulScenarioRegistry::create('lua-replies')->run($client, 42, 0);
        self::assertSame($corrupt ? 'failed' : 'passed', $outcome->status, $outcome->failure ?? '');
        self::assertSame($corrupt ? ['eval', 'evalsha'] : ['eval', 'evalsha', 'eval', 'evalsha', 'eval', 'evalsha'], $calls);
        self::assertNotEmpty($outcome->steps[0]->argumentCode);
        if ($corrupt) {
            self::assertStringContainsString('lua-0:evalsha', $outcome->failure ?? '');
        } else {
            $repeat = StatefulScenarioRegistry::create('lua-replies')->run($client, 42, 0);
            self::assertSame('passed', $repeat->status, $repeat->failure ?? '');
            self::assertSame(array_column($outcome->steps, 'argumentCode'), array_column($repeat->steps, 'argumentCode'));
        }
    }

    public function testCallerTransactionIsNotDisturbed(): void
    {
        $client = $this->createMock(Redis::class);
        $client->method('getMode')->willReturn(Redis::MULTI);
        $client->expects(self::never())->method('eval');
        $client->expects(self::never())->method('evalsha');
        $client->expects(self::never())->method('discard');
        self::assertSame('failed', StatefulScenarioRegistry::create('lua-replies')->run($client, 42, 0)->status);
    }
}
