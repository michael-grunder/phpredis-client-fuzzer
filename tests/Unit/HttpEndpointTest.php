<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Http\FuzzerEndpoint;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class HttpEndpointTest extends TestCase
{
    public function testInvalidRuntimeOverrideFailsBeforeResolvingAClient(): void
    {
        $providerCalled = false;
        $endpoint = new FuzzerEndpoint(function () use (&$providerCalled): \Redis {
            $providerCalled = true;
            return new \Redis();
        });

        $response = $endpoint->handle(['steps' => 'not-an-integer']);

        self::assertFalse($response['ok']);
        self::assertFalse($providerCalled);
    }

    public function testJsonResponseReportsProviderFailures(): void
    {
        $endpoint = new FuzzerEndpoint(static function (): never {
            throw new \RuntimeException('client unavailable');
        });

        $response = json_decode($endpoint->handleJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['ok' => false, 'error' => 'client unavailable'], $response);
    }

    public function testServerControlledCacheSaturationConfigurationIsPreserved(): void
    {
        $endpoint = new FuzzerEndpoint(
            static fn (): \Relay\Relay => new \Relay\Relay(),
            new RunConfiguration(
                maxSteps: 1,
                seed: 42,
                keys: 1,
                commands: ['isconnected'],
                includeLocal: true,
                saturateChance: 1.0,
                saturateSteps: 1,
                saturateTarget: PHP_INT_MAX,
            ),
        );

        $response = $endpoint->handle();

        self::assertTrue($response['ok']);
        self::assertSame(1, $response['result']['saturation_events'] ?? null);
        self::assertSame(5, $response['result']['saturation_reads'] ?? null);
        $configuration = $response['result']['configuration'] ?? null;
        self::assertIsArray($configuration);
        self::assertSame(PHP_INT_MAX, $configuration['saturateTarget'] ?? null);
        self::assertSame('natural', $configuration['saturateMode'] ?? null);
    }
}
