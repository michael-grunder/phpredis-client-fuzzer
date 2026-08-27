<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\Log\Log;
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

    public function testCatchPatternStopsAfterAMatchingWarning(): void
    {
        $errorReporting = error_reporting(E_ALL);
        Log::setLogger(static function (string $level, string $message, array $context): void {
            trigger_error('Special warning to catch', E_USER_WARNING);
        });

        try {
            $result = (new Fuzzer())->run(
                [new \Redis()],
                new RunConfiguration(
                    maxSteps: 10,
                    seed: 42,
                    commands: ['isconnected'],
                    includeLocal: true,
                    catchPattern: 'SPECIAL WARNING',
                ),
            );
        } finally {
            Log::setLogger(null);
            error_reporting($errorReporting);
        }

        self::assertSame(1, $result->steps);
        self::assertStringContainsString('Special warning to catch', $result->caughtDiagnostic ?? '');
        self::assertSame(1, array_sum($result->warnings));
    }

    public function testCatchPatternStopsAfterAMatchingException(): void
    {
        Log::setLogger(static function (string $level, string $message, array $context): never {
            throw new \RuntimeException('Special exception to catch');
        });

        try {
            $result = (new Fuzzer())->run(
                [new \Redis()],
                new RunConfiguration(
                    maxSteps: 10,
                    seed: 42,
                    commands: ['isconnected'],
                    includeLocal: true,
                    catchPattern: 'SPECIAL EXCEPTION',
                ),
            );
        } finally {
            Log::setLogger(null);
        }

        self::assertSame(1, $result->steps);
        self::assertSame(
            'RuntimeException: Special exception to catch',
            $result->caughtDiagnostic,
        );
        self::assertSame(
            ['RuntimeException: Special exception to catch' => 1],
            $result->commands['isconnected']['exceptions'],
        );
    }
}
