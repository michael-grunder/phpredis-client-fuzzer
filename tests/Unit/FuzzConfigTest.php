<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\Commands\SlotPolicy;
use PHPUnit\Framework\TestCase;

final class FuzzConfigTest extends TestCase
{
    public function testGeneratedSequenceIsReproducibleWithMtSeed(): void
    {
        mt_srand(12345);
        $first = $this->sample();
        mt_srand(12345);
        $second = $this->sample();

        self::assertSame($first, $second);
    }

    public function testMillisecondAbsoluteExpiriesUseMillisecondEpoch(): void
    {
        mt_srand(12345);
        $configuration = new FuzzConfig();
        $before = time() * 1000;
        $expiry = $configuration->getRandomExpireAt(true);
        $after = time() * 1000;

        self::assertGreaterThanOrEqual($before + 1000, $expiry);
        self::assertLessThanOrEqual($after + 1_000_000, $expiry);
    }

    public function testClusterKeysUseStableHashTags(): void
    {
        mt_srand(42);
        $configuration = (new FuzzConfig())
            ->setCluster(true)
            ->setShards(4)
            ->setKeys(10);

        self::assertMatchesRegularExpression('/^string:\{[0-3]\}:\d+$/', $configuration->getRandomKey(Command::STRING));
    }

    public function testExactKeysUseTheConfiguredNamespaceWithoutRandomness(): void
    {
        $standalone = (new FuzzConfig())->setKeys(10)->setShards(4);
        self::assertSame('hash:7', $standalone->getKeyAt(Command::HASH, 7, 3));

        $cluster = (new FuzzConfig())->setCluster(true)->setKeys(10)->setShards(4);
        self::assertSame('zset:{3}:7', $cluster->getKeyAt(Command::ZSET, 7, 3));
    }

    public function testSingleSlotCommandsShareOneHashTag(): void
    {
        mt_srand(7);
        $configuration = $this->clusterConfiguration();

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(SlotPolicy::SameSlot, $configuration->beginStep(false));
            self::assertCount(1, array_unique($this->tags($configuration->getRandomKeys(Command::STRING, 8))));
        }
    }

    public function testCrossSlotCapableCommandsMayMixHashTags(): void
    {
        mt_srand(7);
        $configuration = $this->clusterConfiguration();

        $mixed = false;
        for ($i = 0; $i < 50 && ! $mixed; $i++) {
            self::assertSame(SlotPolicy::Unconstrained, $configuration->beginStep(true));
            $mixed = count(array_unique($this->tags($configuration->getRandomKeys(Command::STRING, 8)))) > 1;
        }

        self::assertTrue($mixed, 'cross-slot capable commands should not be pinned to one hash tag');
    }

    public function testCrossSlotChanceForcesDistinctHashTags(): void
    {
        mt_srand(7);
        $configuration = $this->clusterConfiguration()->setCrossSlot(1.0);

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(SlotPolicy::CrossSlot, $configuration->beginStep(false));
            $tags = $this->tags($configuration->getRandomKeys(Command::STRING, 4));
            self::assertCount(4, array_unique($tags));
        }
    }

    public function testCrossSlotChanceIsIgnoredOutsideOfCluster(): void
    {
        mt_srand(7);
        $configuration = (new FuzzConfig())->setShards(8)->setKeys(10)->setCrossSlot(1.0);

        self::assertSame(SlotPolicy::SameSlot, $configuration->beginStep(false));
    }

    public function testCrossSlotChanceIsIgnoredWithOneShard(): void
    {
        mt_srand(7);
        $configuration = (new FuzzConfig())
            ->setCluster(true)
            ->setShards(1)
            ->setCrossSlot(1.0);

        self::assertSame(SlotPolicy::SameSlot, $configuration->beginStep(false));
    }

    public function testTheCrossSlotFlagMarksTheClientDistributedCommands(): void
    {
        $registry = new Registry();

        foreach (['del', 'mget', 'mset', 'msetnx', 'unlink'] as $name) {
            $command = $registry->get($name);
            self::assertNotNull($command, $name);
            self::assertSame(Command::CROSSSLOT, $command->flags() & Command::CROSSSLOT, $name);
        }

        foreach (['sinter', 'msetex', 'rename', 'pfcount', 'exists'] as $name) {
            $command = $registry->get($name);
            self::assertNotNull($command, $name);
            self::assertSame(0, $command->flags() & Command::CROSSSLOT, $name);
        }
    }

    public function testCrossSlotChanceIsValidatedAndClamped(): void
    {
        mt_srand(7);
        $configuration = $this->clusterConfiguration()->setCrossSlot(-1.0);
        self::assertSame(SlotPolicy::SameSlot, $configuration->beginStep(false));

        $configuration->setCrossSlot(2.0);
        self::assertSame(SlotPolicy::CrossSlot, $configuration->beginStep(false));
    }

    public function testInvalidLimitsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FuzzConfig())->setKeys(0);
    }

    private function clusterConfiguration(): FuzzConfig
    {
        return (new FuzzConfig())
            ->setCluster(true)
            ->setShards(8)
            ->setKeys(10);
    }

    /**
     * @param string[] $keys
     * @return string[]
     */
    private function tags(array $keys): array
    {
        $tags = [];
        foreach ($keys as $key) {
            if (preg_match('/\{([^}]+)\}/', $key, $matches) !== 1) {
                self::fail("generated cluster key has no hash tag: {$key}");
            }
            $tags[] = $matches[1];
        }

        return $tags;
    }

    /** @return array<mixed> */
    private function sample(): array
    {
        $configuration = (new FuzzConfig())
            ->setKeys(20)
            ->setMembers(5)
            ->setMinLen(4)
            ->setMaxLen(8)
            ->setMaxKeys(3);

        return [
            $configuration->getRandomString(),
            $configuration->getRandomKey(Command::HASH),
            $configuration->getRandomMembers(Command::SET),
            $configuration->randomRange(),
            $configuration->randomScoreRange(),
        ];
    }
}
