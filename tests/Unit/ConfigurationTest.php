<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    /** @return iterable<string, array{ClientType, string, bool}> */
    public static function clients(): iterable
    {
        yield 'PhpRedis standalone' => [ClientType::Redis, \Redis::class, false];
        yield 'PhpRedis cluster' => [ClientType::RedisCluster, \RedisCluster::class, true];
        yield 'Relay standalone' => [ClientType::Relay, \Relay\Relay::class, false];
        yield 'Relay cluster' => [ClientType::RelayCluster, \Relay\Cluster::class, true];
    }

    #[DataProvider('clients')]
    public function testClientTypesMapToSupportedClasses(ClientType $type, string $class, bool $cluster): void
    {
        self::assertSame($class, $type->className());
        self::assertSame($cluster, $type->isCluster());
    }

    public function testRunConfigurationHasShortSafeDefaults(): void
    {
        $configuration = new RunConfiguration();

        self::assertSame(100, $configuration->maxSteps);
        self::assertFalse($configuration->raw);
        self::assertFalse($configuration->includeAdmin);
        self::assertFalse($configuration->includeFlush);
        self::assertFalse($configuration->includeCrashing);
    }

    public function testRunConfigurationRequiresALimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RunConfiguration(maxSteps: 0, maxSeconds: 0.0);
    }

    public function testClientConfigurationRejectsEmptyClusterSeeds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientConfiguration(type: ClientType::RedisCluster, seeds: []);
    }
}
