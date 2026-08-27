<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class FuzzerTest extends TestCase
{
    public function testAtLeastOneClientIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Fuzzer())->run([]);
    }

    public function testUnsupportedObjectsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Fuzzer())->run([new \stdClass()]);
    }

    public function testRunsAClientLocalCommandWithoutARedisServer(): void
    {
        $result = (new Fuzzer())->run(
            [new \Redis()],
            new RunConfiguration(
                maxSteps: 3,
                seed: 42,
                commands: ['isconnected'],
                includeLocal: true,
            ),
        );

        self::assertSame(42, $result->seed);
        self::assertSame(3, $result->steps);
        self::assertSame(['isconnected'], $result->selectedCommands);
        self::assertSame(3, $result->commands['isconnected']['count']);
        $clients = $result->environment['clients'];
        self::assertIsArray($clients);
        $client = $clients[0] ?? null;
        self::assertIsArray($client);
        self::assertSame(\Redis::class, $client['class'] ?? null);
    }
}
