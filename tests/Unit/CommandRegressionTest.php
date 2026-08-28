<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command\scan;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\zdiffstore;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use PHPUnit\Framework\TestCase;

final class CommandRegressionTest extends TestCase
{
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
}
