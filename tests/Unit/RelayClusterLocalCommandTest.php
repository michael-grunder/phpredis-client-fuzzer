<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\CommandFilter;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\flushslotcache;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\invalidateslotcaches;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class RelayClusterLocalCommandTest extends TestCase
{
    public function testSlotCacheCommandsAreLocalOnly(): void
    {
        $default = (new CommandFilter())->apply(new Registry(), new RunConfiguration());
        self::assertNull($default->get('flushslotcache'));
        self::assertNull($default->get('invalidateslotcaches'));

        $local = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(
                commands: ['flushslotcache', 'invalidateslotcaches'],
                includeLocal: true,
            ),
        );

        foreach (['flushslotcache', 'invalidateslotcaches'] as $name) {
            $command = $local->get($name);
            self::assertNotNull($command);
            self::assertSame(Command::LOCAL, $command->flags());
        }
    }

    public function testSlotCacheCommandsInvokeRelayClusterMethods(): void
    {
        if (!class_exists(\Relay\Cluster::class)) {
            self::markTestSkipped('Relay is not loaded');
        }

        $client = new class extends \Relay\Cluster {
            public int $flushes = 0;

            public static int $invalidations = 0;

            public function __construct()
            {
            }

            public function flushSlotCache(): bool
            {
                $this->flushes++;

                return true;
            }

            public static function invalidateSlotCaches(): bool
            {
                self::$invalidations++;

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
        };
        $config = new FuzzConfig();

        self::assertTrue((new flushslotcache())->fuzz($client, $config));
        self::assertTrue((new invalidateslotcaches())->fuzz($client, $config));
        self::assertSame(1, $client->flushes);
        self::assertSame(1, $client::$invalidations);
    }

    public function testSlotCacheCommandsRejectOtherClients(): void
    {
        $client = new \Redis();
        $config = new FuzzConfig();

        self::assertFalse((new flushslotcache())->fuzz($client, $config));
        self::assertFalse((new invalidateslotcaches())->fuzz($client, $config));
    }
}
