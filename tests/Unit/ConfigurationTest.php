<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\InvocationMode;
use Mgrunder\PhpredisCommandFuzzer\RelayClusterOptions;
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
        self::assertFalse($configuration->includeStateful);
        self::assertNull($configuration->catchPattern);
        self::assertFalse($configuration->differential);
        self::assertSame(10.0, $configuration->differentialToleranceMs);
        self::assertSame(1.0, $configuration->differentialPollIntervalMs);
        self::assertSame(0.0, $configuration->saturateChance);
        self::assertNull($configuration->saturateSteps);
        self::assertNull($configuration->saturateTarget);
        self::assertSame([], $configuration->scenarios);
        self::assertSame(InvocationMode::Strict, $configuration->invocationMode);
        self::assertSame('strict', $configuration->jsonSerialize()['invocationMode']);
    }

    public function testInvocationModesAreParsedExplicitly(): void
    {
        self::assertSame(InvocationMode::Strict, InvocationMode::parse(' STRICT '));
        self::assertSame(InvocationMode::Coercive, InvocationMode::parse('coercive'));
    }

    public function testUnknownInvocationModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected strict or coercive');

        InvocationMode::parse('weak');
    }

    public function testDifferentialTimingMustBeValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('differentialPollIntervalMs');

        new RunConfiguration(differentialPollIntervalMs: 0.0);
    }

    public function testDifferentialTimingMustBeFinite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('differentialToleranceMs');

        new RunConfiguration(differentialToleranceMs: INF);
    }

    public function testRunConfigurationRejectsAnEmptyCatchPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('catchPattern cannot be empty');

        new RunConfiguration(catchPattern: '');
    }

    public function testRunConfigurationRequiresALimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RunConfiguration(maxSteps: 0, maxSeconds: 0.0);
    }

    public function testCacheSaturationSettingsAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('saturateSteps');

        new RunConfiguration(saturateChance: 0.5, saturateSteps: 0);
    }

    public function testCacheSaturationChanceIsAProbability(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('saturateChance');

        new RunConfiguration(saturateChance: 50.0);
    }

    public function testCacheSaturationTargetMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('saturateTarget');

        new RunConfiguration(saturateChance: 0.5, saturateTarget: 0);
    }

    public function testCacheSaturationTargetIsSerializedAsBytes(): void
    {
        $configuration = new RunConfiguration(saturateTarget: 100 * 1024 ** 2);

        self::assertSame(100 * 1024 ** 2, $configuration->saturateTarget);
        self::assertSame(
            100 * 1024 ** 2,
            $configuration->jsonSerialize()['saturateTarget'] ?? null,
        );
    }

    public function testClientConfigurationRejectsEmptyClusterSeeds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientConfiguration(type: ClientType::RedisCluster, seeds: []);
    }

    public function testRelayClusterOptionsDefaultToUnset(): void
    {
        $options = new RelayClusterOptions();

        self::assertTrue($options->isEmpty());
        self::assertNull($options->failover);
        self::assertNull($options->distribute);
        self::assertNull($options->nodeReadTimeout);
        self::assertNull($options->multikeyReordering);
    }

    public function testRelayClusterOptionsNormalizeModeNames(): void
    {
        $options = new RelayClusterOptions(
            failover: ' RANDOM-REPLICA ',
            distribute: 'Replicas',
            nodeReadTimeout: 0.25,
            multikeyReordering: 'ALL',
        );

        self::assertFalse($options->isEmpty());
        self::assertSame('random_replica', $options->failover);
        self::assertSame('replicas', $options->distribute);
        self::assertSame(0.25, $options->nodeReadTimeout);
        self::assertSame('all', $options->multikeyReordering);
    }

    /** @return iterable<string, array{callable(): RelayClusterOptions, string}> */
    public static function invalidRelayClusterOptions(): iterable
    {
        yield 'failover mode' => [
            static fn (): RelayClusterOptions => new RelayClusterOptions(failover: 'distribute'),
            'Unknown failover mode',
        ];
        yield 'distribute mode' => [
            static fn (): RelayClusterOptions => new RelayClusterOptions(distribute: 'primary'),
            'Unknown distribute mode',
        ];
        yield 'reordering mode' => [
            static fn (): RelayClusterOptions => new RelayClusterOptions(multikeyReordering: 'both'),
            'Unknown multikey reordering mode',
        ];
        yield 'negative timeout' => [
            static fn (): RelayClusterOptions => new RelayClusterOptions(nodeReadTimeout: -1.0),
            'cannot be negative',
        ];
    }

    /** @param callable(): RelayClusterOptions $construct */
    #[DataProvider('invalidRelayClusterOptions')]
    public function testRelayClusterOptionsRejectUnknownModes(callable $construct, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');

        $construct();
    }

    public function testRelayClusterOptionsRequireTheRelayClusterClientType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/require the relay-cluster client type/');

        new ClientConfiguration(
            type: ClientType::Relay,
            relayCluster: new RelayClusterOptions(failover: 'all'),
        );
    }

    public function testRelayClusterOptionsAreAcceptedForRelayCluster(): void
    {
        $configuration = new ClientConfiguration(
            type: ClientType::RelayCluster,
            relayCluster: new RelayClusterOptions(distribute: 'all', nodeReadTimeout: 0.5),
        );

        self::assertSame('all', $configuration->relayCluster->distribute);
        self::assertSame(0.5, $configuration->relayCluster->nodeReadTimeout);
    }
}
