<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\CommandFilter;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\client as ClientCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
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
    public function testSubcommandsKillRealAddressesAndKeepClusterRouting(ClientType $type, bool $raw): void
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
            ? "id=1 addr=127.0.0.1:12345 name=worker\n"
                . "id=2 addr=[::1]:23456 name=other\n"
            : [['id' => '1', 'addr' => '127.0.0.1:12345'], ['id' => '2', 'addr' => '[::1]:23456']];
        $invoker = new ClientCommandRecordingInvoker($reply);
        $command->setClientInvoker($invoker);

        mt_srand(42);
        for ($step = 0; $step < 80; $step++) {
            $config->beginCommand($command, $raw);
            if ($raw) {
                $command->fuzzRaw($client, $config);
            } else {
                $command->fuzz($client, $config);
            }
        }

        $seen = [];
        $previous = [];
        $previousRoute = null;
        foreach ($invoker->calls as $call) {
            self::assertSame($raw ? 'rawCommand' : 'client', $call['method']);
            $args = $call['arguments'];
            $route = $type->isCluster() ? array_shift($args) : null;
            if ($type->isCluster()) {
                self::assertIsString($route);
                self::assertMatchesRegularExpression('/^phpredis-command-fuzzer:\{\d+\}:route$/', $route);
            }
            if ($raw) {
                self::assertSame('client', array_shift($args));
            }
            $subcommand = $args[0];
            self::assertIsString($subcommand);
            $seen[$subcommand] = true;
            if ($subcommand === 'KILL') {
                self::assertSame(['LIST'], $previous);
                self::assertSame($previousRoute, $route);
                self::assertContains($args[1], ['127.0.0.1:12345', '[::1]:23456']);
            } elseif ($subcommand === 'SETNAME') {
                self::assertIsString($args[1]);
                self::assertMatchesRegularExpression('/^phpredis-fuzz-[a-f0-9]+$/', $args[1]);
            } else {
                self::assertCount(1, $args);
            }
            $previous = $args;
            $previousRoute = $route;
        }
        self::assertEqualsCanonicalizing(['LIST', 'GETNAME', 'SETNAME', 'KILL'], array_keys($seen));

        $firstRun = $invoker->calls;
        $invoker->calls = [];
        mt_srand(42);
        for ($step = 0; $step < 80; $step++) {
            $config->beginCommand($command, $raw);
            if ($raw) {
                $command->fuzzRaw($client, $config);
            } else {
                $command->fuzz($client, $config);
            }
        }
        self::assertSame($firstRun, $invoker->calls);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableLists(): iterable
    {
        yield 'error reply' => [false];
        yield 'empty list' => [[]];
        yield 'malformed list' => [[['addr' => 123], ['name' => 'worker'], ['addr' => '']]];
        yield 'queued raw reply' => ['QUEUED'];
        yield 'malformed raw reply' => ["id=1 laddr=127.0.0.1:6379\n"];
    }

    #[DataProvider('unusableLists')]
    public function testKillDoesNotInventAddressesWhenLookupIsUnusable(mixed $reply): void
    {
        $client = new Redis();
        $command = new ClientCommand();
        $invoker = new ClientCommandRecordingInvoker($reply);
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        mt_srand(42);

        for ($step = 0; $step < 80; $step++) {
            $command->fuzz($client, $config);
            $command->fuzzRaw($client, $config);
        }
        foreach ($invoker->calls as $call) {
            self::assertNotContains('KILL', $call['arguments']);
        }
    }
}
