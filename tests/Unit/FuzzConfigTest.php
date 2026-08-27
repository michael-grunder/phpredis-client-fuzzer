<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
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

    public function testClusterKeysUseStableHashTags(): void
    {
        mt_srand(42);
        $configuration = (new FuzzConfig())
            ->setCluster(true)
            ->setShards(4)
            ->setKeys(10);

        self::assertMatchesRegularExpression('/^string:\{[0-3]\}:\d+$/', $configuration->getRandomKey(Command::STRING));
    }

    public function testInvalidLimitsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FuzzConfig())->setKeys(0);
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
