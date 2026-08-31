<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command\scan;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\config as ConfigCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\flushall as FlushAllCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\get as GetCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\mget as MGetCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\rawcommand as RawCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\unwatch as UnwatchCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\zdiffstore;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\SlotPolicy;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use PHPUnit\Framework\TestCase;

trait RecordsRawClusterCommands
{
    /** @var list<array{route: array<mixed>|string, command: string, args: array<mixed>}> */
    public array $calls = [];

    public function __construct()
    {
    }

    /** @param array<mixed>|string $keyOrAddress */
    public function rawCommand(
        array|string $keyOrAddress,
        string $command,
        mixed ...$args,
    ): mixed {
        $this->calls[] = [
            'route' => $keyOrAddress,
            'command' => $command,
            'args' => $args,
        ];

        return true;
    }

    public function getLastError(): ?string
    {
        return null;
    }

    /** @return list<array{0: string, 1: int}> */
    public function _masters(): array
    {
        return [];
    }
}

final class CommandRegressionTest extends TestCase
{
    public function testRedisClusterRawCommandUsesTheGeneratedKeySlot(): void
    {
        $client = new class extends \RedisCluster {
            use RecordsRawClusterCommands;
        };

        $this->assertRawGetUsesGeneratedKeySlot(
            $client,
            static fn (): array => $client->calls,
        );
    }

    public function testRelayClusterRawCommandUsesTheGeneratedKeySlot(): void
    {
        if (!class_exists(\Relay\Cluster::class)) {
            self::markTestSkipped('Relay is not loaded');
        }

        $client = new class extends \Relay\Cluster {
            use RecordsRawClusterCommands;
        };

        $this->assertRawGetUsesGeneratedKeySlot(
            $client,
            static fn (): array => $client->calls,
        );
    }

    public function testRawCrossSlotPolicyRoutesAwayFromTheGeneratedKey(): void
    {
        $client = new class extends \RedisCluster {
            use RecordsRawClusterCommands;
        };
        $config = $this->rawClusterConfiguration()->setCrossSlot(1.0);
        $command = new GetCommand();

        self::assertSame(SlotPolicy::CrossSlot, $config->beginCommand($command, raw: true));
        $command->fuzzRaw($client, $config);

        self::assertSame('get', $client->calls[0]['command']);
        self::assertIsString($client->calls[0]['route']);
        self::assertIsString($client->calls[0]['args'][0] ?? null);
        self::assertNotSame(
            $this->tag($client->calls[0]['route']),
            $this->tag($client->calls[0]['args'][0]),
        );
    }

    public function testRawClientDistributedCommandIsPinnedToOneSlot(): void
    {
        $client = new class extends \RedisCluster {
            use RecordsRawClusterCommands;
        };
        $config = $this->rawClusterConfiguration();
        $command = new MGetCommand();

        self::assertSame(SlotPolicy::SameSlot, $config->beginCommand($command, raw: true));
        $command->fuzzRaw($client, $config);

        self::assertSame('mget', $client->calls[0]['command']);
        self::assertIsString($client->calls[0]['route']);
        $routeTag = $this->tag($client->calls[0]['route']);
        foreach ($client->calls[0]['args'] as $key) {
            self::assertIsString($key);
            self::assertSame($routeTag, $this->tag($key));
        }
    }

    public function testMetaRawCommandLoadsCatalogCommandsAndUsesClusterArgumentOrder(): void
    {
        $client = new class extends \RedisCluster {
            use RecordsRawClusterCommands;
        };
        $config = $this->rawClusterConfiguration();
        $command = new RawCommand();
        $config->beginCommand($command, raw: true);

        $command->fuzzRaw($client, $config);

        self::assertIsString($client->calls[0]['route']);
        self::assertContains($client->calls[0]['command'], ['get', 'smembers', 'hgetall', 'lrange']);
    }

    public function testRawChaosUsesStandaloneArgumentOrderAndScalarExtras(): void
    {
        $client = new class extends \Redis {
            /** @var list<array{command: string, args: list<mixed>}> */
            public array $calls = [];

            public function rawCommand(string $command, mixed ...$args): mixed
            {
                $this->calls[] = ['command' => $command, 'args' => array_values($args)];

                return true;
            }

            public function getLastError(): ?string
            {
                return null;
            }
        };
        $config = new FuzzConfig();
        $command = new GetCommand();
        mt_srand(42);
        $config->beginCommand($command, raw: true);

        $command->fuzzRawChaos($client, $config);

        self::assertSame('get', $client->calls[0]['command']);
        self::assertLessThanOrEqual(7, count($client->calls[0]['args']));
        foreach ($client->calls[0]['args'] as $argument) {
            self::assertTrue(is_scalar($argument));
        }
    }

    public function testRawChaosPrependsAClusterRouteBeforeTheCommand(): void
    {
        $client = new class extends \RedisCluster {
            use RecordsRawClusterCommands;
        };
        $config = $this->rawClusterConfiguration();
        $command = new GetCommand();
        mt_srand(42);
        $config->beginCommand($command, raw: true);

        $command->fuzzRawChaos($client, $config);

        self::assertIsString($client->calls[0]['route']);
        self::assertMatchesRegularExpression(
            '/^phpredis-command-fuzzer:\\{[0-9]+}:route$/',
            $client->calls[0]['route'],
        );
        self::assertSame('get', $client->calls[0]['command']);
        foreach ($client->calls[0]['args'] as $argument) {
            self::assertTrue(is_scalar($argument));
        }
    }

    public function testRawClusterRoutingIsNotDuplicatedInCommandArguments(): void
    {
        $client = new class extends \RedisCluster {
            use RecordsRawClusterCommands;
        };
        $config = $this->rawClusterConfiguration();
        $commands = [new FlushAllCommand(), new ConfigCommand(), new UnwatchCommand()];

        foreach ($commands as $command) {
            $config->beginCommand($command, raw: true);
            $command->fuzzRaw($client, $config);
        }

        self::assertSame('flushall', $client->calls[0]['command']);
        self::assertSame([], $client->calls[0]['args']);
        self::assertSame('config', $client->calls[1]['command']);
        self::assertSame('GET', $client->calls[1]['args'][0] ?? null);
        self::assertCount(2, $client->calls[1]['args']);
        self::assertSame('unwatch', $client->calls[2]['command']);
        self::assertSame([], $client->calls[2]['args']);
    }

    public function testScanStartsWithANullCursor(): void
    {
        $client = new class extends \Redis {
            public int|string|null $receivedCursor = -1;

            /**
             * @param-out int $iterator
             * @return list<string>
             */
            public function scan(
                int|string|null &$iterator,
                ?string $pattern = null,
                int $count = 0,
                ?string $type = null,
            ): array {
                $this->receivedCursor = $iterator;
                $iterator = 0;

                return ['covered'];
            }

            public function getMode(): int
            {
                return \Redis::ATOMIC;
            }

            public function getLastError(): ?string
            {
                return null;
            }
        };

        $reply = (new scan())->fuzz($client, new FuzzConfig());

        self::assertSame(['covered'], $reply);
        self::assertNull($client->receivedCursor);
    }

    public function testClusterScanKeepsOneRouteKeyForTheCursor(): void
    {
        $client = new class extends \RedisCluster {
            /** @var list<array{cursor: int|string|null, route: array<mixed>|string}> */
            public array $calls = [];

            public function __construct()
            {
            }

            /**
             * @param array<mixed>|string $keyOrAddress
             * @param-out int $iterator
             * @return list<string>
             */
            public function scan(
                int|string|null &$iterator,
                array|string $keyOrAddress,
                ?string $pattern = null,
                int $count = 0,
            ): array {
                $this->calls[] = ['cursor' => $iterator, 'route' => $keyOrAddress];
                $iterator = count($this->calls) === 1 ? 123 : 0;

                return ['covered'];
            }

            public function getMode(): int
            {
                return \Redis::ATOMIC;
            }

            public function getLastError(): ?string
            {
                return null;
            }
        };
        $config = (new FuzzConfig())->setCluster(true)->setShards(4);
        $command = new scan();

        $command->fuzz($client, $config);
        $command->fuzz($client, $config);

        self::assertNull($client->calls[0]['cursor']);
        self::assertSame(123, $client->calls[1]['cursor']);
        self::assertSame($client->calls[0]['route'], $client->calls[1]['route']);
    }

    public function testZdiffstoreDestinationSharesTheSourceHashSlot(): void
    {
        $client = new class extends \RedisCluster {
            public string $destination = '';

            /** @var string[] */
            public array $keys = [];

            public function __construct()
            {
            }

            /** @param string[] $keys */
            public function zdiffstore(string $destination, array $keys): int
            {
                $this->destination = $destination;
                $this->keys = $keys;

                return 0;
            }

            public function getLastError(): ?string
            {
                return null;
            }

            /** @return list<array{0: string, 1: int}> */
            public function _masters(): array
            {
                return [];
            }
        };
        $config = (new FuzzConfig())
            ->setCluster(true)
            ->setShards(8)
            ->setMaxKeys(5);
        $command = new zdiffstore();
        $config->beginCommand($command);

        $command->fuzz($client, $config);

        $keys = [$client->destination, ...$client->keys];
        $tags = array_map(static function (string $key): string {
            if (preg_match('/\{([^}]+)\}/', $key, $matches) !== 1) {
                self::fail("Generated cluster key has no hash tag: {$key}");
            }

            return $matches[1];
        }, $keys);
        self::assertCount(1, array_unique($tags));
    }

    public function testReferenceArgumentsUseVariablesInReproductionScripts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpredis-fuzz-script');
        self::assertIsString($path);
        $client = new \Redis();

        try {
            ScriptLogger::init($path, clients: [$client]);
            ScriptLogger::logReference(
                $client,
                'scan',
                [null, null, 10, null],
                0,
            );
        } finally {
            ScriptLogger::finish();
        }

        $script = file_get_contents($path);
        unlink($path);
        self::assertIsString($script);
        self::assertStringContainsString('$phpredisFuzzReference1 = NULL;', $script);
        self::assertStringContainsString(
            '->scan($phpredisFuzzReference1, NULL, 10, NULL);',
            $script,
        );
    }

    /**
     * @param callable(): list<array{route: array<mixed>|string, command: string, args: array<mixed>}> $calls
     */
    private function assertRawGetUsesGeneratedKeySlot(
        \RedisCluster|\Relay\Cluster $client,
        callable $calls,
    ): void
    {
        $config = $this->rawClusterConfiguration();
        $command = new GetCommand();
        self::assertSame(SlotPolicy::SameSlot, $config->beginCommand($command, raw: true));

        $command->fuzzRaw($client, $config);

        $call = $calls()[0];
        self::assertSame('get', $call['command']);
        self::assertIsString($call['route']);
        self::assertIsString($call['args'][0] ?? null);
        self::assertSame($this->tag($call['route']), $this->tag($call['args'][0]));
    }

    private function rawClusterConfiguration(): FuzzConfig
    {
        mt_srand(42);

        return (new FuzzConfig())
            ->setCluster(true)
            ->setShards(8)
            ->setKeys(10)
            ->setMaxKeys(5);
    }

    private function tag(string $key): string
    {
        if (preg_match('/\{([^}]+)\}/', $key, $matches) !== 1) {
            self::fail("Cluster key has no hash tag: {$key}");
        }

        return $matches[1];
    }
}
