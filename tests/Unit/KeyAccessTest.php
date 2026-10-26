<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\AliasTable;
use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\Registry;
use Mgrunder\RedisClientTester\Commands\SlotPolicy;
use Mgrunder\RedisClientTester\Tester;
use Mgrunder\RedisClientTester\HasWeight;
use Mgrunder\RedisClientTester\InvocationMode;
use Mgrunder\RedisClientTester\KeyAccessSampler;
use Mgrunder\RedisClientTester\KeyReadGroup;
use Mgrunder\RedisClientTester\RunConfiguration;
use Mgrunder\RedisClientTester\WeightedIndexSampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class KeyAccessTest extends TestCase
{
    public function testLegacyCommandDrawsAreUnchanged(): void
    {
        $items = [];
        foreach ([0.0, 1.0, 3.0, 0.5] as $id => $weight) {
            $items[] = new readonly class($id, $weight) implements HasWeight {
                public function __construct(public int $id, private float $value) {}
                public function weight(): float { return $this->value; }
            };
        }
        mt_srand(12345);
        $table = new AliasTable($items);
        $actual = [];
        for ($i = 0; $i < 20; $i++) {
            $actual[] = $table->pick()->id;
        }
        // Captured from the command selector before extracting its sampler.
        self::assertSame([2, 1, 2, 2, 1, 2, 2, 2, 1, 3, 2, 1, 1, 1, 2, 2, 2, 2, 2, 1], $actual);
    }

    public function testZeroWeightItemsAreNeverSelected(): void
    {
        $sampler = new WeightedIndexSampler([0.0, 5.0, 0.0]);
        $random = new Randomizer(new Xoshiro256StarStar(42));
        for ($i = 0; $i < 100; $i++) {
            self::assertSame(1, $sampler->pick($random));
        }
    }

    public function testProfileAndDrawsAreIndependentOfGlobalRandomness(): void
    {
        $first = new KeyAccessSampler(42, 1.2, $this->readGroups());
        $second = new KeyAccessSampler(42, 1.2, $this->readGroups());
        $different = new KeyAccessSampler(43, 1.2, $this->readGroups());
        $a = $b = $c = [];
        for ($i = 0; $i < 100; $i++) {
            $flags = $i % 2 === 0 ? Command::READ : Command::WRITE;
            $a[] = $first->pick(100, $flags);
            mt_srand($i);
            mt_rand();
            $b[] = $second->pick(100, $flags);
            $c[] = $different->pick(100, $flags);
        }
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);

        mt_srand(5678);
        $expected = mt_rand();
        mt_srand(5678);
        (new KeyAccessSampler(17, 1.0, $this->readGroups()))->pick(100, Command::READ);
        self::assertSame($expected, mt_rand());
    }

    public function testZipfFrequenciesAndUniformFrequencies(): void
    {
        $zipf = array_fill(0, 32, 0);
        $uniform = $zipf;
        $hot = new KeyAccessSampler(12345, 1.0);
        $flat = new KeyAccessSampler(12345);
        for ($i = 0; $i < 64000; $i++) {
            $zipf[$hot->pick(32, 0)]++;
            $uniform[$flat->pick(32, 0)]++;
        }
        rsort($zipf);
        $harmonic = array_sum(array_map(static fn (int $rank): float => 1.0 / $rank, range(1, 32)));
        foreach ($zipf as $index => $count) {
            self::assertEqualsWithDelta(1.0 / (($index + 1) * $harmonic), $count / 64000, 0.006);
        }
        foreach ($uniform as $count) {
            self::assertEqualsWithDelta(1.0 / 32, $count / 64000, 0.004);
        }
    }

    public function testKeysKeepOpposingReadWriteAffinities(): void
    {
        $sampler = new KeyAccessSampler(12345, readGroups: [new KeyReadGroup(1.0, 0.95), new KeyReadGroup(1.0, 0.05)]);
        $reads = $writes = array_fill(0, 32, 0);
        for ($i = 0; $i < 64000; $i++) {
            $reads[$sampler->pick(32, Command::READ)]++;
            $writes[$sampler->pick(32, Command::WRITE)]++;
        }
        $readHeavy = $writeHeavy = 0;
        foreach ($reads as $key => $readCount) {
            if ($readCount > $writes[$key] * 3) {
                $readHeavy++;
            } elseif ($writes[$key] > $readCount * 3) {
                $writeHeavy++;
            }
        }
        self::assertGreaterThan(5, $readHeavy);
        self::assertGreaterThan(5, $writeHeavy);
        self::assertSame(32, $readHeavy + $writeHeavy);
    }

    public function testSingleKeyAndChangedKeyCountStayInBounds(): void
    {
        $sampler = new KeyAccessSampler(42, 2.0, $this->readGroups());
        foreach ([1, 10, 2, 1] as $keys) {
            for ($i = 0; $i < 100; $i++) {
                $key = $sampler->pick($keys, Command::WRITE);
                self::assertGreaterThanOrEqual(0, $key);
                self::assertLessThan($keys, $key);
            }
        }
    }

    public function testClusterPoliciesAndExactLookupsSurviveWeightedSelection(): void
    {
        $config = (new FuzzConfig())->setCluster(true)->setShards(4)->setKeys(32)
            ->setKeyAccess(new KeyAccessSampler(42, 1.5, $this->readGroups()));
        $registry = new Registry();
        $get = $registry->get('get');
        $mget = $registry->get('mget');
        self::assertNotNull($get);
        self::assertNotNull($mget);
        foreach ([false, true] as $raw) {
            self::assertSame(SlotPolicy::SameSlot, $config->beginCommand($get, $raw));
            $keys = $config->getRandomKeys(Command::STRING, 100);
            self::assertCount(1, array_unique(array_map(static fn (string $key): string => explode(':', $key)[1], $keys)));
        }
        self::assertSame(SlotPolicy::Unconstrained, $config->beginCommand($mget));
        self::assertSame(SlotPolicy::SameSlot, $config->beginCommand($mget, true));
        $config->setCrossSlot(1.0);
        self::assertSame(SlotPolicy::CrossSlot, $config->beginCommand($get));
        $keys = $config->getRandomKeys(Command::STRING, 4);
        self::assertCount(4, array_unique(array_map(static fn (string $key): string => explode(':', $key)[1], $keys)));

        self::assertSame('hash:{2}:31', $config->getKeyAt(Command::HASH, 31, 2));
        $config->setWrongtype(1.0);
        self::assertStringNotContainsString('custom:', $config->getRandomKey('custom'));
    }

    public function testMutatingReadsAndDeleteUseWriteAffinity(): void
    {
        $expected = new KeyAccessSampler(42, 0.0, $this->readGroups());
        $registry = new Registry();
        $expectedKeys = [];
        for ($i = 0; $i < 100; $i++) {
            $expectedKeys[] = $expected->pick(32, Command::WRITE);
        }
        foreach (['set', 'getdel', 'getex', 'del'] as $name) {
            $config = (new FuzzConfig())->setKeys(32)->setKeyAccess(new KeyAccessSampler(42, 0.0, $this->readGroups()));
            $command = $registry->get($name);
            self::assertNotNull($command);
            $config->beginCommand($command);
            $actual = [];
            for ($i = 0; $i < 100; $i++) {
                $actual[] = (int) explode(':', $config->getRandomKey(Command::STRING))[1];
            }
            self::assertSame($expectedKeys, $actual, $name);
        }
    }

    /** @return iterable<string, array{InvocationMode}> */
    public static function invocationModes(): iterable
    {
        yield 'strict' => [InvocationMode::Strict];
        yield 'coercive' => [InvocationMode::Coercive];
    }

    #[DataProvider('invocationModes')]
    public function testRunnerUsesAndReportsTheSeededProfile(InvocationMode $mode): void
    {
        $client = new class extends \Redis {
            /** @var list<string> */
            public array $keys = [];
            public function get(string $key): mixed { $this->keys[] = $key; return false; }
            public function rawCommand(string $command, mixed ...$args): mixed { return [['get']]; }
            public function getLastError(): ?string { return null; }
            public function getOption(int $option): mixed { return 0; }
        };
        $configuration = new RunConfiguration(
            maxSteps: 300, seed: 12345, keys: 32, commands: ['get'],
            invocationMode: $mode, keySkew: 2.0, keyReadGroups: $this->readGroups(),
        );
        $result = (new Tester())->run([$client], $configuration);
        self::assertSame(2.0, $result->configuration['keySkew']);
        self::assertSame($configuration->jsonSerialize()['keyReadGroups'], $result->configuration['keyReadGroups']);
        self::assertCount(300, $client->keys);
        $counts = array_count_values($client->keys);
        if ($counts === []) {
            self::fail('The runner generated no keys');
        }
        self::assertGreaterThan(100, max($counts));
        $first = $client->keys;
        $client->keys = [];
        (new Tester())->run([$client], $configuration);
        self::assertSame($first, $client->keys);
    }

    /** @return list<KeyReadGroup> */
    private function readGroups(): array
    {
        return [new KeyReadGroup(4.0, 0.95), new KeyReadGroup(2.0, 0.5), new KeyReadGroup(4.0, 0.05)];
    }
}
