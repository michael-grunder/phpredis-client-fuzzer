<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command\fullscan;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use PHPUnit\Framework\TestCase;

final class FullscanCommandTest extends TestCase
{
    public function testConsumesEveryFullscanBatch(): void
    {
        if (!class_exists(\Relay\Cluster::class)) {
            self::markTestSkipped('Relay is not loaded');
        }

        $client = new class extends \Relay\Cluster {
            /** @var list<array{mixed, int, ?string}> */
            public array $calls = [];

            public int $iterations = 0;

            public function __construct()
            {
            }

            /** @return \Generator<int, list<string>, void, void> */
            public function fullscan(mixed $match = null, int $count = 0,
                                     ?string $type = null): \Generator
            {
                $this->calls[] = [$match, $count, $type];

                $this->iterations++;
                yield ['string:{1}:1', 'hash:{2}:2'];
                $this->iterations++;
                yield [];
                $this->iterations++;
                yield ['list:{3}:3'];
            }

            public function getMode(bool $masked = false): int
            {
                return \Redis::ATOMIC;
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
        mt_srand(9281);
        $command = new fullscan();

        $result = $command->fuzz($client, (new FuzzConfig())->setMaxKeys(10));

        self::assertSame(['batches' => 3, 'keys' => 3], $result);
        self::assertSame(3, $client->iterations);
        self::assertCount(1, $client->calls);
        self::assertSame(Command::READ | Command::SCAN, $command->flags());
        self::assertSame(Command::ANY, $command->type());
    }

    public function testRedisClusterDoesNotSelectFullscan(): void
    {
        $client = new class extends \RedisCluster {
            public function __construct()
            {
            }

            /** @param array<mixed>|string $keyOrAddress */
            public function rawCommand(array|string $keyOrAddress, string $command,
                                       mixed ...$args): mixed
            {
                return false;
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

        $this->expectException(\UnderflowException::class);
        $this->expectExceptionMessage('None of the selected commands are supported');

        (new Fuzzer())->run(
            [$client],
            new RunConfiguration(maxSteps: 1, seed: 9281, commands: ['fullscan']),
        );
    }

    public function testReproductionScriptConsumesTheGenerator(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpredis-fuzz-fullscan');
        self::assertIsString($path);
        $client = new \Redis();

        try {
            ScriptLogger::init($path, clients: [$client]);
            ScriptLogger::logIterable($client, 'fullscan', ['string:*', 10, 'string']);
        } finally {
            ScriptLogger::finish();
        }

        $script = file_get_contents($path);
        unlink($path);
        self::assertIsString($script);
        self::assertStringContainsString(
            "\$phpredisFuzzIterable1 = \$redis1->fullscan('string:*', 10, 'string');",
            $script,
        );
        self::assertStringContainsString(
            'foreach ($phpredisFuzzIterable1 as $phpredisFuzzBatch) {}',
            $script,
        );
    }
}
