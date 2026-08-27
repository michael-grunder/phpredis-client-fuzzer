<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Http\FuzzerEndpoint;
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
}
