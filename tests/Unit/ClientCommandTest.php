<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\ClientInvoker;
use Mgrunder\RedisClientTester\ClientType;
use Mgrunder\RedisClientTester\CommandFilter;
use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\Command\client as ClientCommand;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\Registry;
use Mgrunder\RedisClientTester\RunConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class ClientCommandRecordingInvoker implements ClientInvoker
{
    /** @var list<array{method: string, arguments: list<mixed>}> */
    public array $calls = [];

    public function __construct(public mixed $listReply)
    {
    }

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];
        return in_array('LIST', $arguments, true) ? $this->listReply : true;
    }
}

final class ClientCommandTest extends TestCase
{
    public function testClientRequiresOnlyTheAdminOptIn(): void
    {
        $default = (new CommandFilter())->apply(new Registry(), new RunConfiguration());
        self::assertNull($default->get('client'));

        $admin = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(commands: ['client'], includeAdmin: true),
        );
        $command = $admin->get('client');
        self::assertInstanceOf(ClientCommand::class, $command);
        self::assertSame(Command::ADMIN | Command::READ | Command::WRITE, $command->flags());
        self::assertSame(Command::NONE, $command->type());
    }

    /** @return iterable<string, array{ClientType, bool}> */
    public static function surfaces(): iterable
    {
        foreach (ClientType::cases() as $type) {
            yield $type->value . ' method' => [$type, false];
            yield $type->value . ' raw' => [$type, true];
        }
    }

    #[DataProvider('surfaces')]
    public function testSubcommandsUseObservedTargetsAndNativeArgumentShapes(ClientType $type, bool $raw): void
    {
        $class = $type->className();
        if (!class_exists($class)) {
            self::markTestSkipped($class . ' is not loaded');
        }
        $client = match ($type) {
            ClientType::Redis => $this->createStub(Redis::class),
            ClientType::RedisCluster => $this->createStub(RedisCluster::class),
            ClientType::Relay => $this->createStub(Relay::class),
            ClientType::RelayCluster => $this->createStub(Cluster::class),
        };
        $command = new ClientCommand();
        $config = (new FuzzConfig())->setCluster($type->isCluster());
        $reply = $raw
            ? "id=1 addr=127.0.0.1:12345 user=default laddr=127.0.0.1:6379 name=worker\n"
                . "id=2 addr=[::1]:23456 user=default laddr=[::1]:6379 name=other\n"
            : [['id' => '1', 'addr' => '127.0.0.1:12345', 'user' => 'default', 'laddr' => '127.0.0.1:6379'],
                ['id' => '2', 'addr' => '[::1]:23456', 'user' => 'default', 'laddr' => '[::1]:6379']];
        $invoker = new ClientCommandRecordingInvoker($reply);
        $command->setClientInvoker($invoker);

        mt_srand(42);
        for ($step = 0; $step < 1200; $step++) {
            $config->beginCommand($command, $raw);
            if ($raw) {
                $command->fuzzRaw($client, $config);
            } else {
                $command->fuzz($client, $config);
            }
        }

        self::assertCount(1200, $invoker->calls, 'Each fuzz step must make exactly one invocation');
        $seen = [];
        $killFilters = [];
        $listedRoutes = [];
        foreach ($invoker->calls as $call) {
            self::assertSame($raw ? 'rawCommand' : 'client', $call['method']);
            $args = $call['arguments'];
            $route = $type->isCluster() ? array_shift($args) : null;
            if ($route !== null) {
                self::assertIsString($route);
            }
            if ($type->isCluster()) {
                self::assertIsString($route);
                self::assertMatchesRegularExpression('/^redis-client-tester:\{\d+\}:route$/', $route);
            }
            if ($raw) {
                self::assertSame('client', array_shift($args));
            }
            $subcommand = $args[0];
            self::assertIsString($subcommand);
            $seen[$subcommand] = true;
            if ($subcommand === 'KILL') {
                if (count($args) === 2 && is_string($args[1])) {
                    self::assertArrayHasKey($route ?? '', $listedRoutes);
                    self::assertContains($args[1], ['127.0.0.1:12345', '[::1]:23456']);
                    $killFilters['legacy'] = true;
                } else {
                    self::assertFalse($type === ClientType::RedisCluster && !$raw);
                    if ($type === ClientType::Redis && !$raw) {
                        self::assertCount(2, $args);
                        self::assertIsArray($args[1]);
                        $filters = $args[1];
                    } else {
                        $filters = [];
                        for ($i = 1; $i < count($args); $i += 2) {
                            self::assertIsString($args[$i]);
                            $filters[$args[$i]] = $args[$i + 1];
                        }
                    }
                    self::assertNotEmpty($filters);
                    foreach ($filters as $filter => $value) {
                        self::assertIsString($value);
                        $killFilters[(string) $filter] = true;
                    }
                    if (isset($filters['ID'])) {
                        self::assertArrayHasKey($route ?? '', $listedRoutes);
                        self::assertContains($filters['ID'], ['1', '2']);
                    }
                    if (isset($filters['TYPE'])) {
                        self::assertIsString($filters['TYPE']);
                        self::assertContains($filters['TYPE'], ['normal', 'master', 'replica', 'slave', 'pubsub']);
                        $killFilters['type:' . $filters['TYPE']] = true;
                    }
                    if (count($filters) === 1 && isset($filters['SKIPME'])) {
                        self::assertIsString($filters['SKIPME']);
                        $killFilters['all:' . $filters['SKIPME']] = true;
                    }
                }
            } elseif ($subcommand === 'SETNAME') {
                self::assertIsString($args[1]);
                self::assertMatchesRegularExpression('/^redis-client-tester-[a-f0-9]+$/', $args[1]);
            } else {
                self::assertCount(1, $args);
            }
            if ($subcommand === 'LIST') {
                $listedRoutes[$route ?? ''] = true;
            }
        }
        self::assertEqualsCanonicalizing(['LIST', 'GETNAME', 'SETNAME', 'KILL'], array_keys($seen));

        self::assertArrayHasKey('legacy', $killFilters);
        if ($type !== ClientType::RedisCluster || $raw) {
            foreach (['ID', 'ADDR', 'USER', 'LADDR', 'all:yes', 'all:no', 'type:normal', 'type:master',
                'type:replica', 'type:slave', 'type:pubsub'] as $variant) {
                self::assertArrayHasKey($variant, $killFilters);
            }
            if ($raw || $type === ClientType::Relay || $type === ClientType::RelayCluster) {
                self::assertArrayHasKey('MAXAGE', $killFilters);
            } else {
                self::assertArrayNotHasKey('MAXAGE', $killFilters);
            }
        }

        $firstRun = $invoker->calls;
        $config = (new FuzzConfig())->setCluster($type->isCluster());
        $invoker->calls = [];
        mt_srand(42);
        for ($step = 0; $step < 1200; $step++) {
            $config->beginCommand($command, $raw);
            if ($raw) {
                $command->fuzzRaw($client, $config);
            } else {
                $command->fuzz($client, $config);
            }
        }
        self::assertSame($firstRun, $invoker->calls);
    }

    #[DataProvider('surfaces')]
    public function testUsesOnlyUnexpiredObservationsForTheCurrentRoute(ClientType $type, bool $raw): void
    {
        if (!class_exists($type->className())) {
            self::markTestSkipped($type->className() . ' is not loaded');
        }
        $client = match ($type) {
            ClientType::Redis => $this->createStub(Redis::class),
            ClientType::RedisCluster => $this->createStub(RedisCluster::class),
            ClientType::Relay => $this->createStub(Relay::class),
            ClientType::RelayCluster => $this->createStub(Cluster::class),
        };
        $command = new ClientCommand();
        $config = (new FuzzConfig())->setCluster($type->isCluster());
        $config->recentClients()->observe($client, $type->isCluster() ? 'route-a' : '',
            [['id' => '12345', 'addr' => '127.0.0.1:54321']]);

        foreach (['route-a', 'route-b', 'expired'] as $phase) {
            if ($phase === 'expired') {
                for ($step = 0; $step < 256; $step++) {
                    $config->beginStep(false);
                }
            }
            $route = $phase === 'expired' ? 'route-a' : $phase;
            // Queued LIST replies must neither replace nor refresh the observation.
            $invoker = new ClientCommandRecordingInvoker($client);
            $command->setClientInvoker($invoker);
            mt_srand(42);
            for ($step = 0; $step < 50; $step++) {
                $config->beginCommand($command, $raw);
                $command->setRawRoutingKey($route);
                if ($raw) {
                    $command->fuzzRaw($client, $config);
                } else {
                    $command->fuzz($client, $config);
                }
            }
            self::assertCount(50, $invoker->calls);
            $targeted = 0;
            foreach ($invoker->calls as $call) {
                $args = $call['arguments'];
                if ($type->isCluster()) {
                    self::assertSame($route, array_shift($args));
                }
                if ($raw) {
                    array_shift($args);
                }
                if ($args[0] !== 'KILL') {
                    continue;
                }
                $values = is_array($args[1]) ? array_values($args[1]) : $args;
                if (in_array('12345', $values, true) || in_array('127.0.0.1:54321', $values, true)) {
                    $targeted++;
                }
            }
            if ($phase === 'expired' || ($type->isCluster() && $phase === 'route-b')) {
                self::assertSame(0, $targeted);
            } else {
                self::assertGreaterThan(0, $targeted);
            }
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableLists(): iterable
    {
        yield 'error reply' => [false];
        yield 'empty list' => [[]];
        yield 'malformed list' => [[['addr' => 123], ['name' => 'worker'], ['addr' => '']]];
        yield 'queued raw reply' => ['QUEUED'];
        yield 'malformed raw reply' => ["id=invalid laddr=127.0.0.1:6379\n"];
    }

    #[DataProvider('unusableLists')]
    public function testUnusableListsStillAllowIndependentFilters(mixed $reply): void
    {
        $client = new Redis();
        $command = new ClientCommand();
        $invoker = new ClientCommandRecordingInvoker($reply);
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        mt_srand(42);

        for ($step = 0; $step < 80; $step++) {
            $config->beginCommand($command);
            $command->fuzz($client, $config);
        }
        $kills = 0;
        foreach ($invoker->calls as $call) {
            if ($call['arguments'][0] === 'KILL') {
                $kills++;
                self::assertIsArray($call['arguments'][1]);
                self::assertArrayNotHasKey('ID', $call['arguments'][1]);
                self::assertArrayNotHasKey('ADDR', $call['arguments'][1]);
            }
        }
        self::assertGreaterThan(0, $kills);
    }
}
