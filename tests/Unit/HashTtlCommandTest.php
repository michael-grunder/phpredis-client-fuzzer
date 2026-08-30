<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hexpire;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hexpireat;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hexpiretime;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hgetdel;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hgetex;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\himport;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hotkeys;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hpersist;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hpexpire;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hpexpireat;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hpexpiretime;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hpttl;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hsetex;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\httl;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class HashCommandRecordingInvoker implements ClientInvoker
{
    /** @var list<array{method: string, arguments: list<mixed>}> */
    public array $calls = [];

    public function invoke(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
    ): mixed {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        return true;
    }
}

final class DeterministicHashConfig extends FuzzConfig
{
    private int $member = 0;

    public function getRandomKey(string $type, ?int $shard = null): string
    {
        return 'hash-key';
    }

    /** @return list<string> */
    public function getRandomMembers(string $type, ?int $count = null): array
    {
        return ['field:a', 'field:b'];
    }

    public function getRandomMember(string $type, array $qualifiers = []): string
    {
        return 'field:' . $this->member++;
    }

    public function getMembers(): int
    {
        return 2;
    }

    public function getRandomValue(
        Redis|RedisCluster|Relay|Cluster $client,
        string $type,
    ): mixed {
        return 'value:' . $this->member++;
    }

    /** @return list<mixed> */
    public function getRandomValues(
        Redis|RedisCluster|Relay|Cluster $client,
        string $type,
        ?int $count = null,
    ): array {
        return array_fill(0, $count ?? 1, 'value');
    }

    public function getRandomString(): string
    {
        return 'token';
    }

    public function getRandomExpire(bool $millis = false): int
    {
        return $millis ? 12_000 : 12;
    }

    public function getRandomExpireAt(bool $millis = false): int
    {
        return $millis ? 1_900_000_012_000 : 1_900_000_012;
    }
}

final class HashTtlCommandTest extends TestCase
{
    private Redis $client;
    private DeterministicHashConfig $config;

    protected function setUp(): void
    {
        $this->client = new Redis();
        $this->config = new DeterministicHashConfig();
        mt_srand(12345);
    }

    public function testRegistryDiscoversEveryRequestedCommand(): void
    {
        $registry = new Registry();
        $names = [
            'hexpire', 'hexpireat', 'hexpiretime', 'hgetdel', 'hgetex',
            'himport', 'hotkeys', 'hpersist', 'hpexpire', 'hpexpireat',
            'hpexpiretime', 'hpttl', 'hsetex', 'httl',
        ];

        foreach ($names as $name) {
            self::assertNotNull($registry->get($name), $name);
        }

        $himport = $registry->get('himport');
        $hotkeys = $registry->get('hotkeys');
        self::assertNotNull($himport);
        self::assertNotNull($hotkeys);
        self::assertSame(Command::STATEFUL, $himport->flags() & Command::STATEFUL);
        self::assertSame(Command::ADMIN, $hotkeys->flags() & Command::ADMIN);
        self::assertSame(Command::STATEFUL, $hotkeys->flags() & Command::STATEFUL);
    }

    public function testExpiryCommandsShareMethodAndRawShapes(): void
    {
        $commands = [
            [new hexpire(), 12],
            [new hpexpire(), 12_000],
            [new hexpireat(), 1_900_000_012],
            [new hpexpireat(), 1_900_000_012_000],
        ];

        foreach ($commands as [$command, $expiry]) {
            $invoker = $this->invoker($command);
            $command->fuzz($this->client, $this->config);
            $call = $invoker->calls[0];

            self::assertSame($command->name(), $call['method']);
            self::assertSame('hash-key', $call['arguments'][0]);
            self::assertSame($expiry, $call['arguments'][1]);
            self::assertSame(['field:a', 'field:b'], $call['arguments'][2]);
            if (isset($call['arguments'][3])) {
                self::assertContains($call['arguments'][3], ['NX', 'XX', 'GT', 'LT']);
            }

            $command->fuzzRaw($this->client, $this->config);
            $raw = $invoker->calls[1];
            self::assertSame('rawCommand', $raw['method']);
            self::assertSame($command->name(), $raw['arguments'][0]);
            self::assertSame('hash-key', $raw['arguments'][1]);
            self::assertSame($expiry, $raw['arguments'][2]);
            $fields = array_search('FIELDS', $raw['arguments'], true);
            self::assertIsInt($fields);
            self::assertSame([2, 'field:a', 'field:b'], array_slice($raw['arguments'], $fields + 1));
        }
    }

    public function testFieldListCommandsUseFieldsBlockOnlyForRawCalls(): void
    {
        $commands = [
            new hexpiretime(), new hgetdel(), new hpersist(),
            new hpexpiretime(), new hpttl(), new httl(),
        ];

        foreach ($commands as $command) {
            $invoker = $this->invoker($command);
            $command->fuzz($this->client, $this->config);
            self::assertSame(
                ['hash-key', ['field:a', 'field:b']],
                $invoker->calls[0]['arguments'],
            );

            $command->fuzzRaw($this->client, $this->config);
            self::assertSame(
                [$command->name(), 'hash-key', 'FIELDS', 2, 'field:a', 'field:b'],
                $invoker->calls[1]['arguments'],
            );
        }
    }

    public function testHGetExCoversEverySupportedExpiryStructure(): void
    {
        $command = new hgetex();
        $invoker = $this->invoker($command);

        for ($i = 0; $i < 400; $i++) {
            $command->fuzz($this->client, $this->config);
        }

        $structures = [];
        $tokens = [];
        foreach ($invoker->calls as $call) {
            self::assertSame('hgetex', $call['method']);
            self::assertSame('hash-key', $call['arguments'][0]);
            self::assertSame(['field:a', 'field:b'], $call['arguments'][1]);

            if (count($call['arguments']) === 2) {
                $structures['omitted'] = true;
                continue;
            }

            $expiry = $call['arguments'][2];
            if ($expiry === null) {
                $structures['null'] = true;
            } elseif ($expiry === 'PERSIST') {
                $structures['string'] = true;
                $tokens['PERSIST'] = true;
            } elseif (is_array($expiry)) {
                $structures[array_is_list($expiry) ? 'list' : 'map'] = true;
                foreach ($expiry as $key => $value) {
                    if (is_string($key)) {
                        $tokens[$key] = true;
                    } elseif (is_string($value)) {
                        $tokens[$value] = true;
                    }
                }
            }
        }

        foreach (['omitted', 'null', 'string', 'list', 'map'] as $structure) {
            self::assertArrayHasKey($structure, $structures);
        }
        foreach (['EX', 'PX', 'EXAT', 'PXAT', 'PERSIST'] as $token) {
            self::assertArrayHasKey($token, $tokens);
        }
    }

    public function testHGetExRawCallsCoverEveryServerExpiryToken(): void
    {
        $command = new hgetex();
        $invoker = $this->invoker($command);

        for ($i = 0; $i < 300; $i++) {
            $command->fuzzRaw($this->client, $this->config);
        }

        $tokens = [];
        $omitted = false;
        foreach ($invoker->calls as $call) {
            $fields = array_search('FIELDS', $call['arguments'], true);
            self::assertIsInt($fields);
            $options = array_slice($call['arguments'], 2, $fields - 2);
            if ($options === []) {
                $omitted = true;
            } else {
                $token = $options[0];
                self::assertIsString($token);
                $tokens[$token] = true;
            }
            self::assertSame([2, 'field:a', 'field:b'], array_slice($call['arguments'], $fields + 1));
        }

        self::assertTrue($omitted);
        foreach (['EX', 'PX', 'EXAT', 'PXAT', 'PERSIST'] as $token) {
            self::assertArrayHasKey($token, $tokens);
        }
    }

    public function testHSetExCoversConditionsAndEveryExpiryStructure(): void
    {
        $command = new hsetex();
        $invoker = $this->invoker($command);

        for ($i = 0; $i < 600; $i++) {
            $command->fuzz($this->client, $this->config);
        }

        $structures = [];
        $tokens = [];
        foreach ($invoker->calls as $call) {
            self::assertSame('hsetex', $call['method']);
            self::assertSame('hash-key', $call['arguments'][0]);
            self::assertIsArray($call['arguments'][1]);
            self::assertNotSame([], $call['arguments'][1]);

            if (count($call['arguments']) === 2) {
                $structures['omitted'] = true;
                continue;
            }

            $options = $call['arguments'][2];
            if ($options === null) {
                $structures['null'] = true;
                continue;
            }

            self::assertIsArray($options);
            $structures[$options === [] ? 'empty' : 'array'] = true;
            foreach ($options as $key => $value) {
                if (is_string($key)) {
                    $tokens[$key] = true;
                } elseif (is_string($value)) {
                    $tokens[$value] = true;
                }
            }
        }

        foreach (['omitted', 'null', 'empty', 'array'] as $structure) {
            self::assertArrayHasKey($structure, $structures);
        }
        foreach (['FNX', 'FXX', 'EX', 'PX', 'EXAT', 'PXAT', 'KEEPTTL'] as $token) {
            self::assertArrayHasKey($token, $tokens);
        }
    }

    public function testHSetExRawCallsHaveCompleteFieldPairs(): void
    {
        $command = new hsetex();
        $invoker = $this->invoker($command);
        $tokens = [];

        for ($i = 0; $i < 400; $i++) {
            $command->fuzzRaw($this->client, $this->config);
        }

        foreach ($invoker->calls as $call) {
            $fields = array_search('FIELDS', $call['arguments'], true);
            self::assertIsInt($fields);
            foreach (array_slice($call['arguments'], 2, $fields - 2) as $token) {
                if (is_string($token)) {
                    $tokens[$token] = true;
                }
            }

            $count = $call['arguments'][$fields + 1];
            self::assertIsInt($count);
            self::assertCount($count * 2, array_slice($call['arguments'], $fields + 2));
        }

        foreach (['FNX', 'FXX', 'EX', 'PX', 'EXAT', 'PXAT', 'KEEPTTL'] as $token) {
            self::assertArrayHasKey($token, $tokens);
        }
    }

    public function testRelaySpecificScalarExpiryFormsAreGenerated(): void
    {
        if (!class_exists(Relay::class)) {
            self::markTestSkipped('Relay is not loaded');
        }

        $client = new Relay();
        $seen = [];
        foreach ([new hgetex(), new hsetex()] as $command) {
            $invoker = $this->invoker($command);
            for ($i = 0; $i < 300; $i++) {
                $command->fuzz($client, $this->config);
            }

            foreach ($invoker->calls as $call) {
                $expiry = $call['arguments'][2] ?? null;
                if (is_int($expiry)) {
                    $seen[$command->name()]['int'] = true;
                } elseif (is_float($expiry)) {
                    $seen[$command->name()]['float'] = true;
                }
            }
        }

        foreach (['hgetex', 'hsetex'] as $name) {
            self::assertTrue($seen[$name]['int'] ?? false, "{$name} integer expiry");
            self::assertTrue($seen[$name]['float'] ?? false, "{$name} float expiry");
        }
    }

    public function testHImportExercisesAndCleansUpEveryOperation(): void
    {
        $command = new himport();
        $invoker = $this->invoker($command);

        for ($i = 0; $i < 100; $i++) {
            $command->fuzz($this->client, $this->config);
        }

        $operations = [];
        foreach ($invoker->calls as $call) {
            self::assertSame('himport', $call['method']);
            $operation = $call['arguments'][0];
            self::assertIsString($operation);
            $operations[$operation] = true;
        }
        foreach (['PREPARE', 'SET', 'DISCARD', 'DISCARDALL'] as $operation) {
            self::assertArrayHasKey($operation, $operations);
        }

        $invoker = $this->invoker($command);
        for ($i = 0; $i < 100; $i++) {
            $command->fuzzRaw($this->client, $this->config);
        }
        $operations = [];
        foreach ($invoker->calls as $call) {
            self::assertSame('rawCommand', $call['method']);
            self::assertSame('himport', $call['arguments'][0]);
            $operation = $call['arguments'][1];
            self::assertIsString($operation);
            $operations[$operation] = true;
        }
        foreach (['PREPARE', 'SET', 'DISCARD', 'DISCARDALL'] as $operation) {
            self::assertArrayHasKey($operation, $operations);
        }
    }

    public function testHotkeysCoversSubcommandsAndStartOptions(): void
    {
        $command = new hotkeys();
        $invoker = $this->invoker($command);

        for ($i = 0; $i < 200; $i++) {
            $command->fuzz($this->client, $this->config);
        }

        $subcommands = [];
        $startTokens = [];
        foreach ($invoker->calls as $call) {
            $subcommand = $call['arguments'][0];
            self::assertIsString($subcommand);
            $subcommands[$subcommand] = true;
            if ($subcommand === 'START') {
                $options = $call['arguments'][1];
                self::assertIsArray($options);
                foreach (array_keys($options) as $token) {
                    $startTokens[$token] = true;
                }
            }
        }

        foreach (['HELP', 'GET', 'RESET', 'START', 'STOP'] as $subcommand) {
            self::assertArrayHasKey($subcommand, $subcommands);
        }
        foreach (['METRICS', 'COUNT', 'DURATION', 'SAMPLE', 'SLOTS'] as $token) {
            self::assertArrayHasKey($token, $startTokens);
        }

        $invoker = $this->invoker($command);
        for ($i = 0; $i < 200; $i++) {
            $command->fuzzRaw($this->client, $this->config);
        }

        $sawStart = false;
        foreach ($invoker->calls as $call) {
            self::assertSame('rawCommand', $call['method']);
            self::assertSame('hotkeys', $call['arguments'][0]);
            if (($call['arguments'][1] ?? null) === 'START') {
                $sawStart = true;
                self::assertSame('METRICS', $call['arguments'][2]);
                $count = $call['arguments'][3];
                self::assertIsInt($count);
                self::assertContains($count, [1, 2]);
            }
        }
        self::assertTrue($sawStart);
    }

    private function invoker(Command $command): HashCommandRecordingInvoker
    {
        $invoker = new HashCommandRecordingInvoker();
        $command->setClientInvoker($invoker);

        return $invoker;
    }
}
