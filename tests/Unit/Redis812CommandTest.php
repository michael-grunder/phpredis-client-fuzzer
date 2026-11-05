<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\ClientInvoker;
use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\Registry;
use Mgrunder\RedisClientTester\ScriptLogger;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class Redis812RecordingInvoker implements ClientInvoker
{
    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];
    public int $mode = Redis::ATOMIC;
    public int $yielded = 0;
    public bool $failIteration = false;

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        if ($method === 'getMode') {
            return $this->mode;
        }
        $this->calls[] = ['method' => $method, 'args' => $arguments];
        if ($method === 'bless' && $arguments[0] === 'SCAN') {
            return $this->keys();
        }
        return true;
    }

    /** @return \Generator<int, string> */
    private function keys(): \Generator
    {
        $this->yielded++;
        yield 'prefix:string:{0}:1';
        if ($this->failIteration) {
            throw new \RuntimeException('scan page failed');
        }
        $this->yielded++;
        yield 'prefix:set:{0}:2';
    }
}

final class Redis812Config extends FuzzConfig
{
    public function getRandomExpire(bool $millis = false): int
    {
        return $millis ? 12_000 : 12;
    }

    public function getRandomExpireAt(bool $millis = false): int
    {
        return $millis ? 1_900_000_012_000 : 1_900_000_012;
    }
}

final class Redis812CommandTest extends TestCase
{
    private const SET_COMMANDS = [
        'sexpire', 'spexpire', 'sexpireat', 'spexpireat', 'spersist',
        'sttl', 'spttl', 'sexpiretime', 'spexpiretime', 'saddex',
    ];

    public function testRegistryMetadataAndSetMemberArguments(): void
    {
        $registry = new Registry();
        $config = (new Redis812Config())->setCluster(true)->setShards(4);
        $expiry = ['sexpire' => 12, 'spexpire' => 12_000,
            'sexpireat' => 1_900_000_012, 'spexpireat' => 1_900_000_012_000];
        mt_srand(812);

        foreach ($this->clients() as $client) {
            foreach (self::SET_COMMANDS as $name) {
                $command = $registry->get($name);
                self::assertInstanceOf(Command::class, $command);
                self::assertInstanceOf(FuzzInterface::class, $command);
                self::assertInstanceOf(FuzzRawInterface::class, $command);
                self::assertSame(Command::SET, $command->type());
                self::assertSame(0, $command->flags() & Command::CROSSSLOT);
                self::assertSame(isset($expiry[$name]) || in_array($name, ['saddex', 'spersist'], true)
                    ? Command::WRITE | Command::EXPIRE | Command::INVALIDATING
                    : Command::READ, $command->flags());
                $invoker = new Redis812RecordingInvoker();
                $command->setClientInvoker($invoker);
                $config->beginCommand($command);
                $command->fuzz($client, $config);
                $args = $invoker->calls[0]['args'];
                self::assertSame($name, $invoker->calls[0]['method']);
                self::assertIsString($args[0]);
                self::assertStringStartsWith('set:', $args[0]);
                $members = $args[isset($expiry[$name]) ? 2 : 1];
                self::assertIsArray($members);
                self::assertNotEmpty($members);
                if (isset($expiry[$name])) {
                    self::assertSame($expiry[$name], $args[1]);
                }

                $config->beginCommand($command, raw: true);
                $command->fuzzRaw($client, $config);
                $raw = $invoker->calls[1]['args'];
                self::assertSame('rawCommand', $invoker->calls[1]['method']);
                if ($client instanceof RedisCluster || $client instanceof Cluster) {
                    $route = array_shift($raw);
                    self::assertIsString($route);
                    self::assertIsString($raw[1]);
                    preg_match('/\{[^}]+\}/', $route, $routeTag);
                    preg_match('/\{[^}]+\}/', $raw[1], $keyTag);
                    self::assertNotEmpty($routeTag);
                    self::assertSame($routeTag, $keyTag);
                }
                self::assertSame($name, $raw[0]);
                self::assertNotContains('FIELDS', $raw);
                $index = array_search('MEMBERS', $raw, true);
                self::assertIsInt($index);
                self::assertSame(count($raw) - $index - 2, $raw[$index + 1]);
                if (isset($expiry[$name])) {
                    self::assertSame($expiry[$name], $raw[2]);
                }
            }
        }
    }

    public function testSaddexAndBitrangeOptionsMatchRawProtocol(): void
    {
        $registry = new Registry();
        $config = new Redis812Config();
        foreach (['saddex', 'bitrangeintarray'] as $name) {
            $command = $registry->get($name);
            self::assertInstanceOf(Command::class, $command);
            self::assertInstanceOf(FuzzInterface::class, $command);
            self::assertInstanceOf(FuzzRawInterface::class, $command);
            $seen = [];
            for ($seed = 0; $seed < 100; $seed++) {
                $invoker = new Redis812RecordingInvoker();
                $command->setClientInvoker($invoker);
                mt_srand($seed);
                $command->fuzz(new Redis(), $config);
                mt_srand($seed);
                $command->fuzzRaw(new Redis(), $config);
                $method = $invoker->calls[0]['args'];
                $options = $method[$name === 'saddex' ? 2 : 3];
                self::assertIsArray($options);
                $expected = [$name, ...array_slice($method, 0, $name === 'saddex' ? 1 : 3)];
                foreach ($options as $key => $value) {
                    if (is_string($key)) {
                        $expected[] = $key;
                        $seen[$key] = true;
                    } else {
                        self::assertIsString($value);
                        $seen[$value] = true;
                    }
                    $expected[] = $value;
                }
                if ($name === 'saddex') {
                    self::assertIsArray($method[1]);
                    array_push($expected, 'MEMBERS', count($method[1]), ...$method[1]);
                }
                self::assertSame($expected, $invoker->calls[1]['args']);
            }
            foreach ($name === 'saddex'
                ? ['MNX', 'MXX', 'EX', 'PX', 'EXAT', 'PXAT', 'KEEPTTL']
                : ['BYTE', 'BIT', 'ZEROS', 'LIMIT'] as $token) {
                self::assertArrayHasKey($token, $seen);
            }
        }
    }

    public function testBlessOperationsRoutingAndQueuedModes(): void
    {
        $command = new \Mgrunder\RedisClientTester\Commands\Command\bless();
        self::assertSame(Command::READ | Command::WRITE | Command::ADMIN | Command::SCAN, $command->flags());
        self::assertSame(Command::ANY, $command->type());
        foreach ($this->clients() as $client) {
            foreach ([Redis::ATOMIC, Redis::MULTI, Redis::PIPELINE] as $mode) {
                $invoker = new Redis812RecordingInvoker();
                $invoker->mode = $mode;
                $command->setClientInvoker($invoker);
                $seen = [];
                mt_srand(812);
                for ($i = 0; $i < 50; $i++) {
                    $result = $command->fuzz($client, new FuzzConfig());
                    $call = $invoker->calls[$i];
                    $args = $call['args'];
                    self::assertSame('bless', $call['method']);
                    self::assertIsString($args[0]);
                    $seen[$args[0]] = true;
                    if ($args[0] === 'SCAN') {
                        self::assertSame(Redis::ATOMIC, $mode);
                        self::assertNull($args[1]);
                        self::assertSame('NO-EVICT', $args[2]);
                        self::assertSame(['prefix:string:{0}:1', 'prefix:set:{0}:2'], $result);
                        self::assertCount($client instanceof RedisCluster || $client instanceof Cluster ? 5 : 4, $args);
                    } else {
                        self::assertIsString($args[1]);
                        self::assertCount($args[0] === 'GET' ? 2 : 3, $args);
                        if ($args[0] !== 'GET') {
                            self::assertSame('NO-EVICT', $args[2]);
                        }
                    }
                }
                foreach (['SET', 'CLEAR', 'GET'] as $operation) {
                    self::assertArrayHasKey($operation, $seen);
                }
                self::assertSame($mode === Redis::ATOMIC, isset($seen['SCAN']));
            }
        }
    }

    public function testBlessIterationIsLoggedAndFailuresPropagate(): void
    {
        $command = new \Mgrunder\RedisClientTester\Commands\Command\bless();
        $invoker = new Redis812RecordingInvoker();
        $invoker->failIteration = true;
        $command->setClientInvoker($invoker);
        $client = new Redis();
        $path = tempnam(sys_get_temp_dir(), 'redis812-script-');
        self::assertIsString($path);
        mt_srand(812);
        ScriptLogger::init($path, clients: [$client]);
        try {
            for ($i = 0; $i < 50; $i++) {
                try {
                    $command->fuzz($client, new FuzzConfig());
                } catch (\RuntimeException $exception) {
                    self::assertSame('scan page failed', $exception->getMessage());
                    break;
                }
            }
            self::assertSame(1, $invoker->yielded);
        } finally {
            ScriptLogger::finish();
            $script = file_get_contents($path);
            unlink($path);
        }
        self::assertIsString($script);
        self::assertStringContainsString("->bless('SCAN', NULL, 'NO-EVICT'", $script);
        self::assertStringContainsString('foreach ($redisClientTesterIterable', $script);
    }

    public function testBlessRawScanUsesACursorAndExplicitClusterRoute(): void
    {
        $command = new \Mgrunder\RedisClientTester\Commands\Command\bless();
        $config = (new FuzzConfig())->setCluster(true)->setShards(4);
        foreach ($this->clients() as $client) {
            $invoker = new Redis812RecordingInvoker();
            $command->setClientInvoker($invoker);
            $seen = [];
            mt_srand(812);
            for ($i = 0; $i < 50; $i++) {
                $config->beginCommand($command, raw: true);
                $command->fuzzRaw($client, $config);
                $call = $invoker->calls[$i];
                self::assertSame('rawCommand', $call['method']);
                $args = $call['args'];
                if ($client instanceof RedisCluster || $client instanceof Cluster) {
                    $route = array_shift($args);
                    self::assertIsString($route);
                    self::assertStringStartsWith('redis-client-tester:{', $route);
                }
                self::assertSame('bless', $args[0]);
                self::assertIsString($args[1]);
                $seen[$args[1]] = true;
                if ($args[1] === 'SCAN') {
                    self::assertSame([0, 'NO-EVICT', 'COUNT'], array_slice($args, 2, 3));
                    self::assertIsInt($args[5]);
                    self::assertGreaterThan(0, $args[5]);
                    self::assertCount(6, $args);
                } else {
                    self::assertIsString($args[2]);
                    self::assertCount($args[1] === 'GET' ? 3 : 4, $args);
                }
            }
            foreach (['SET', 'CLEAR', 'GET', 'SCAN'] as $operation) {
                self::assertArrayHasKey($operation, $seen);
            }
        }
    }

    /** @return list<Redis|RedisCluster|Relay|Cluster> */
    private function clients(): array
    {
        $clients = [new Redis(), new class extends RedisCluster {
            public function __construct() {}
        }];
        if (class_exists(Relay::class)) {
            $clients[] = new Relay();
        }
        if (class_exists(Cluster::class)) {
            $clients[] = new class extends Cluster {
                public function __construct() {}
            };
        }
        return $clients;
    }
}
