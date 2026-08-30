<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\bitfield;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\blmovem;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\blpop;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\brpop;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\digest;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\georadiusbymember;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\georadiusbymember_ro;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\increx;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\lmovem;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\sdiffcard;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\sort as SortCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\sort_ro as SortRoCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\sunioncard;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class RemainingCommandRecordingInvoker implements ClientInvoker
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

final class DeterministicRemainingCommandConfig extends FuzzConfig
{
    private int $key = 0;

    public function getRandomKey(string $type, ?int $shard = null): string
    {
        return $type . '-key-' . $this->key++;
    }

    /** @return list<string> */
    public function getRandomKeys(string $type, ?int $count = null): array
    {
        return [$type . '-key-a', $type . '-key-b'];
    }

    public function getMembers(): int
    {
        return 4;
    }

    public function getRandomInt(): int
    {
        return 17;
    }

    public function getRandomFloat(): float
    {
        return 17.5;
    }

    public function getTimeoutFloat(): float
    {
        return 0.125;
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

final class RemainingCommandTest extends TestCase
{
    private Redis $client;
    private DeterministicRemainingCommandConfig $config;

    protected function setUp(): void
    {
        $this->client = new Redis();
        $this->config = new DeterministicRemainingCommandConfig();
        mt_srand(54321);
    }

    public function testRegistryDiscoversEveryRequestedCommandWithSafetyFlags(): void
    {
        $registry = new Registry();
        $names = [
            'bitfield', 'blmovem', 'blpop', 'brpop', 'digest',
            'georadiusbymember', 'georadiusbymember_ro', 'increx', 'lmovem',
            'sdiffcard', 'sunioncard', 'sort', 'sort_ro',
            'restore', 'script', 'xackdel', 'xdelex', 'xnack',
            'vadd', 'vcard', 'vdim', 'vemb', 'vgetattr', 'vinfo', 'vismember',
            'vlinks', 'vrandmember', 'vrange', 'vrem', 'vsetattr', 'vsim',
        ];

        foreach ($names as $name) {
            $command = $registry->get($name);
            self::assertNotNull($command, $name);
            self::assertSame(0, $command->flags() & Command::CROSSSLOT, $name);
        }

        foreach (['blmovem', 'blpop', 'brpop'] as $name) {
            $command = $registry->get($name);
            self::assertNotNull($command);
            self::assertSame(Command::BLOCKING, $command->flags() & Command::BLOCKING);
        }

        $increx = $registry->get('increx');
        $geo = $registry->get('georadiusbymember');
        $geoRo = $registry->get('georadiusbymember_ro');
        self::assertNotNull($increx);
        self::assertNotNull($geo);
        self::assertNotNull($geoRo);
        self::assertSame(Command::EXPIRE, $increx->flags() & Command::EXPIRE);
        self::assertSame(Command::WRITE, $geo->flags() & Command::WRITE);
        self::assertSame(0, $geoRo->flags() & Command::WRITE);
    }

    public function testSimpleKeyAndBlockingPopShapes(): void
    {
        $digest = new digest();
        $invoker = $this->invoker($digest);
        $digest->fuzz($this->client, $this->config);
        $digest->fuzzRaw($this->client, $this->config);
        self::assertSame('digest', $invoker->calls[0]['method']);
        self::assertCount(1, $invoker->calls[0]['arguments']);
        self::assertSame('rawCommand', $invoker->calls[1]['method']);
        self::assertSame('digest', $invoker->calls[1]['arguments'][0]);

        foreach ([new blpop(), new brpop()] as $command) {
            $invoker = $this->invoker($command);
            for ($i = 0; $i < 50; $i++)
                $command->fuzz($this->client, $this->config);

            $forms = [];
            foreach ($invoker->calls as $call) {
                $args = $call['arguments'];
                if (is_array($args[0])) {
                    $forms['array'] = true;
                    self::assertSame(0.125, $args[1]);
                } else {
                    $forms['variadic'] = true;
                    self::assertSame(0.125, $args[array_key_last($args)]);
                }
            }
            self::assertArrayHasKey('array', $forms);
            self::assertArrayHasKey('variadic', $forms);

            $command->fuzzRaw($this->client, $this->config);
            $raw = $invoker->calls[count($invoker->calls) - 1];
            self::assertSame('rawCommand', $raw['method']);
            self::assertSame($command->name(), $raw['arguments'][0]);
            self::assertSame(0.125, $raw['arguments'][count($raw['arguments']) - 1]);
        }
    }

    public function testListMoveManyCoversAllOptionStructuresAndTokens(): void
    {
        foreach ([new lmovem(), new blmovem()] as $command) {
            $invoker = $this->invoker($command);
            for ($i = 0; $i < 500; $i++)
                $command->fuzz($this->client, $this->config);

            $optionIndex = $command instanceof blmovem ? 5 : 4;
            $structures = [];
            $tokens = [];
            foreach ($invoker->calls as $call) {
                $args = $call['arguments'];
                self::assertContains($args[2], ['LEFT', 'RIGHT']);
                self::assertContains($args[3], ['LEFT', 'RIGHT']);
                if ($command instanceof blmovem)
                    self::assertSame(0.125, $args[4]);

                if (!array_key_exists($optionIndex, $args)) {
                    $structures['omitted'] = true;
                } else if ($args[$optionIndex] === null) {
                    $structures['null'] = true;
                } else if ($args[$optionIndex] === []) {
                    $structures['empty'] = true;
                } else {
                    $structures['options'] = true;
                    self::assertIsArray($args[$optionIndex]);
                    foreach ($args[$optionIndex] as $type => $value) {
                        if (!is_string($type) || !is_array($value) ||
                            !isset($value[0], $value[1]) || !is_string($value[1]))
                            self::fail('Malformed LMOVEM option');
                        $tokens[$type] = true;
                        $tokens[$value[1]] = true;
                        self::assertGreaterThan(0, $value[0]);
                    }
                }
            }

            foreach (['omitted', 'null', 'empty', 'options'] as $structure)
                self::assertArrayHasKey($structure, $structures);
            foreach (['COUNT', 'EXACTLY', 'OBO', 'BULK'] as $token)
                self::assertArrayHasKey($token, $tokens);

            $invoker = $this->invoker($command);
            for ($i = 0; $i < 300; $i++)
                $command->fuzzRaw($this->client, $this->config);
            $tokens = [];
            foreach ($invoker->calls as $call) {
                $args = $call['arguments'];
                self::assertSame('rawCommand', $call['method']);
                self::assertSame($command->name(), $args[0]);
                if (isset($args[$optionIndex + 1])) {
                    $type = $args[$optionIndex + 1];
                    $mode = $args[$optionIndex + 3];
                    if (!is_string($type) || !is_string($mode))
                        self::fail('Malformed raw LMOVEM option');
                    $tokens[$type] = true;
                    $tokens[$mode] = true;
                    self::assertGreaterThan(0, $args[$optionIndex + 2]);
                }
            }
            foreach (['COUNT', 'EXACTLY', 'OBO', 'BULK'] as $token)
                self::assertArrayHasKey($token, $tokens);
        }
    }

    public function testBitfieldProducesCompleteValidSubcommandGrammar(): void
    {
        $command = new bitfield();
        $invoker = $this->invoker($command);

        for ($i = 0; $i < 600; $i++) {
            $command->fuzz($this->client, $this->config);
            $command->fuzzRaw($this->client, $this->config);
        }

        $seen = [];
        $empty = false;
        foreach ($invoker->calls as $call) {
            $args = $call['arguments'];
            if ($call['method'] === 'rawCommand') {
                self::assertSame('bitfield', array_shift($args));
            } else {
                self::assertSame('bitfield', $call['method']);
            }
            self::assertIsString(array_shift($args));
            if ($args === [])
                $empty = true;

            for ($i = 0; $i < count($args);) {
                $operation = $args[$i++];
                self::assertIsString($operation);
                if ($operation === 'OVERFLOW') {
                    $mode = $args[$i++];
                    self::assertContains($mode, ['WRAP', 'SAT', 'FAIL']);
                    if (!is_string($mode))
                        self::fail('BITFIELD overflow mode is not a string');
                    $seen[$mode] = true;
                    $operation = $args[$i++];
                    self::assertContains($operation, ['SET', 'INCRBY']);
                }

                self::assertContains($operation, ['GET', 'SET', 'INCRBY']);
                if (!is_string($operation))
                    self::fail('BITFIELD operation is not a string');
                $seen[$operation] = true;
                $encoding = $args[$i++];
                if (!is_string($encoding))
                    self::fail('BITFIELD encoding is not a string');
                self::assertMatchesRegularExpression('/^[iu](?:[1-9]|[1-5][0-9]|6[0-4])$/', $encoding);
                self::assertTrue(is_int($args[$i]) || is_string($args[$i]));
                $i++;
                if ($operation !== 'GET')
                    self::assertIsInt($args[$i++]);
            }
        }

        self::assertTrue($empty);
        foreach (['GET', 'SET', 'INCRBY', 'WRAP', 'SAT', 'FAIL'] as $token)
            self::assertArrayHasKey($token, $seen);
    }

    public function testIncrExCoversNumericAndOptionMatrices(): void
    {
        $command = new increx();
        $invoker = $this->invoker($command);

        for ($i = 0; $i < 1200; $i++)
            $command->fuzz($this->client, $this->config);

        $structures = [];
        $tokens = [];
        $increments = [];
        foreach ($invoker->calls as $call) {
            $args = $call['arguments'];
            self::assertSame('increx', $call['method']);
            if (count($args) === 1) {
                $structures['omitted'] = true;
                continue;
            }

            $increment = $args[1];
            $increments[get_debug_type($increment)] = true;
            if (!array_key_exists(2, $args)) {
                continue;
            }

            $options = $args[2];
            self::assertIsArray($options);
            $structures[$options === [] ? 'empty' : 'options'] = true;
            foreach ($options as $key => $value) {
                if (is_string($key)) {
                    $tokens[$key] = true;
                    if ($key === 'OVERFLOW') {
                        if (!is_string($value))
                            self::fail('INCREX overflow value is not a string');
                        $tokens[$value] = true;
                    }
                    if ($key === 'LBOUND' || $key === 'UBOUND') {
                        $expectedType = is_float($increment) ? 'float' : 'int';
                        self::assertSame($expectedType, get_debug_type($value));
                    }
                } else {
                    self::assertSame('ENX', $value);
                    $tokens['ENX'] = true;
                    self::assertNotEmpty(array_intersect(['EX', 'PX', 'EXAT', 'PXAT'], array_keys($options)));
                }
            }
        }

        foreach (['omitted', 'empty', 'options'] as $structure)
            self::assertArrayHasKey($structure, $structures);
        foreach (['null', 'int', 'float'] as $type)
            self::assertArrayHasKey($type, $increments);
        foreach (['LBOUND', 'UBOUND', 'OVERFLOW', 'FAIL', 'SAT', 'REJECT',
                  'EX', 'PX', 'EXAT', 'PXAT', 'PERSIST', 'ENX'] as $token)
            self::assertArrayHasKey($token, $tokens);

        $invoker = $this->invoker($command);
        for ($i = 0; $i < 900; $i++)
            $command->fuzzRaw($this->client, $this->config);

        $rawTokens = [];
        foreach ($invoker->calls as $call) {
            $args = $call['arguments'];
            self::assertSame('rawCommand', $call['method']);
            self::assertSame('increx', array_shift($args));
            self::assertIsString(array_shift($args));
            while ($args !== []) {
                $token = array_shift($args);
                self::assertIsString($token);
                $rawTokens[$token] = true;
                if ($token === 'SATURATE' || $token === 'PERSIST' || $token === 'ENX')
                    continue;
                self::assertNotSame([], $args);
                array_shift($args);
            }
        }
        foreach (['BYINT', 'BYFLOAT', 'LBOUND', 'UBOUND', 'SATURATE',
                  'EX', 'PX', 'EXAT', 'PXAT', 'PERSIST', 'ENX'] as $token)
            self::assertArrayHasKey($token, $rawTokens);
    }

    public function testGeoByMemberCoversReadAndStoreOptions(): void
    {
        foreach ([new georadiusbymember(), new georadiusbymember_ro()] as $command) {
            $invoker = $this->invoker($command);
            for ($i = 0; $i < 700; $i++) {
                $command->fuzz($this->client, $this->config);
                $command->fuzzRaw($this->client, $this->config);
            }

            $tokens = [];
            foreach ($invoker->calls as $call) {
                $args = $call['arguments'];
                if ($call['method'] === 'rawCommand') {
                    self::assertSame($command->name(), array_shift($args));
                    foreach (array_slice($args, 4) as $token) {
                        if (is_string($token))
                            $tokens[$token] = true;
                    }
                } else {
                    self::assertSame($command->name(), $call['method']);
                    if (isset($args[4])) {
                        self::assertIsArray($args[4]);
                        foreach ($args[4] as $key => $value) {
                            $token = is_string($key) ? $key : $value;
                            if (!is_string($token))
                                self::fail('Geo option is not a string');
                            $tokens[$token] = true;
                        }
                    }
                }
                self::assertIsString($args[0]);
                self::assertIsString($args[1]);
                self::assertIsFloat($args[2]);
                self::assertContains($args[3], ['m', 'km', 'ft', 'mi']);
            }

            foreach (['WITHCOORD', 'WITHDIST', 'WITHHASH', 'ASC', 'DESC', 'COUNT', 'ANY'] as $token)
                self::assertArrayHasKey($token, $tokens);

            if ($command instanceof georadiusbymember) {
                self::assertArrayHasKey('STORE', $tokens);
                self::assertArrayHasKey('STOREDIST', $tokens);
            } else {
                self::assertArrayNotHasKey('STORE', $tokens);
                self::assertArrayNotHasKey('STOREDIST', $tokens);
            }
        }
    }

    public function testSetCardinalityOptionSurfacesMatchEachCommand(): void
    {
        foreach ([new sdiffcard(), new sunioncard()] as $command) {
            $invoker = $this->invoker($command);
            for ($i = 0; $i < 500; $i++) {
                $command->fuzz($this->client, $this->config);
                $command->fuzzRaw($this->client, $this->config);
            }

            $structures = [];
            $tokens = [];
            foreach ($invoker->calls as $call) {
                $args = $call['arguments'];
                if ($call['method'] === 'rawCommand') {
                    self::assertSame($command->name(), array_shift($args));
                    $count = array_shift($args);
                    self::assertIsInt($count);
                    self::assertCount($count, array_slice($args, 0, $count));
                    foreach (array_slice($args, $count) as $token) {
                        if (is_string($token))
                            $tokens[$token] = true;
                    }
                    continue;
                }

                self::assertIsArray($args[0]);
                if (!array_key_exists(1, $args)) {
                    $structures['omitted'] = true;
                } else if ($args[1] === null) {
                    $structures['null'] = true;
                } else if ($args[1] === []) {
                    $structures['empty'] = true;
                } else {
                    $structures['options'] = true;
                    self::assertIsArray($args[1]);
                    foreach ($args[1] as $key => $value) {
                        $token = is_string($key) ? $key : $value;
                        if (!is_string($token))
                            self::fail('Set cardinality option is not a string');
                        $tokens[$token] = true;
                    }
                }
            }

            foreach (['omitted', 'null', 'empty', 'options'] as $structure)
                self::assertArrayHasKey($structure, $structures);
            self::assertArrayHasKey('LIMIT', $tokens);
            if ($command instanceof sunioncard)
                self::assertArrayHasKey('APPROX', $tokens);
            else
                self::assertArrayNotHasKey('APPROX', $tokens);
        }
    }

    public function testSortCoversMethodAndRawOptionSurfaces(): void
    {
        foreach ([new SortCommand(), new SortRoCommand()] as $command) {
            $invoker = $this->invoker($command);
            for ($i = 0; $i < 800; $i++) {
                $command->fuzz($this->client, $this->config);
                $command->fuzzRaw($this->client, $this->config);
            }

            $tokens = [];
            foreach ($invoker->calls as $call) {
                $args = $call['arguments'];
                if ($call['method'] === 'rawCommand') {
                    self::assertSame($command->name(), array_shift($args));
                    $source = array_shift($args);
                    self::assertIsString($source);
                    self::assertMatchesRegularExpression('/^(?:list|set|zset)-key-/', $source);

                    while ($args !== []) {
                        $token = array_shift($args);
                        self::assertIsString($token);
                        $tokens[$token] = true;
                        if ($token === 'BY' || $token === 'STORE' || $token === 'GET') {
                            self::assertNotSame([], $args);
                            self::assertIsString(array_shift($args));
                        } else if ($token === 'LIMIT') {
                            self::assertCount(2, array_splice($args, 0, 2));
                        }
                    }
                    continue;
                }

                self::assertSame($command->name(), $call['method']);
                self::assertIsString($args[0]);
                self::assertMatchesRegularExpression('/^(?:list|set|zset)-key-/', $args[0]);
                if (!isset($args[1]))
                    continue;

                self::assertIsArray($args[1]);
                foreach ($args[1] as $key => $value) {
                    $token = is_string($key) ? $key : $value;
                    if (!is_string($token))
                        self::fail('SORT option is not a string');
                    $tokens[$token] = true;
                    if ($key === 'GET') {
                        foreach (is_array($value) ? $value : [$value] as $pattern)
                            self::assertIsString($pattern);
                    } else if ($key === 'LIMIT') {
                        self::assertIsArray($value);
                        self::assertCount(2, $value);
                    }
                }
            }

            foreach (['BY', 'LIMIT', 'GET', 'ASC', 'DESC', 'ALPHA'] as $token)
                self::assertArrayHasKey($token, $tokens);
            if ($command instanceof SortCommand)
                self::assertArrayHasKey('STORE', $tokens);
            else
                self::assertArrayNotHasKey('STORE', $tokens);
        }
    }

    public function testMultiKeyCommandsKeepRelatedClusterKeysInOneSlot(): void
    {
        $config = (new FuzzConfig())
            ->setCluster(true)
            ->setShards(8)
            ->setMaxKeys(4);

        foreach ([new lmovem(), new blmovem(), new sdiffcard(), new sunioncard()] as $command) {
            $invoker = $this->invoker($command);
            $config->beginCommand($command);
            $command->fuzz($this->client, $config);
            $args = $invoker->calls[0]['arguments'];
            $keys = $command instanceof lmovem || $command instanceof blmovem
                ? array_slice($args, 0, 2)
                : $args[0];
            self::assertIsArray($keys);
            $tags = [];
            foreach ($keys as $key) {
                if (!is_string($key))
                    self::fail('Generated key is not a string');
                $tags[] = $this->slotTag($key);
            }
            self::assertCount(1, array_unique($tags));
        }

        $command = new georadiusbymember();
        $invoker = $this->invoker($command);
        $stores = [];
        for ($i = 0; $i < 300; $i++) {
            $config->beginCommand($command);
            $command->fuzz($this->client, $config);
            $args = $invoker->calls[$i]['arguments'];
            if (!isset($args[4]) || !is_array($args[4]))
                continue;

            foreach (['STORE', 'STOREDIST'] as $store) {
                if (!isset($args[4][$store]) || !is_string($args[4][$store]))
                    continue;
                if (!is_string($args[0]))
                    self::fail('Generated geo source key is not a string');
                $stores[$store] = true;
                self::assertSame($this->slotTag($args[0]), $this->slotTag($args[4][$store]));
            }
        }
        self::assertArrayHasKey('STORE', $stores);
        self::assertArrayHasKey('STOREDIST', $stores);
    }

    private function invoker(Command $command): RemainingCommandRecordingInvoker
    {
        $invoker = new RemainingCommandRecordingInvoker();
        $command->setClientInvoker($invoker);

        return $invoker;
    }

    private function slotTag(string $key): string
    {
        self::assertSame(1, preg_match('/\{([^}]+)\}/', $key, $matches));
        if (!isset($matches[1]))
            self::fail('Generated cluster key has no hash tag');

        return $matches[1];
    }
}
