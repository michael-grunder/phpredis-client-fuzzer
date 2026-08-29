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
        self::assertCount(3, $result->outcomes);
        self::assertSame(1, $result->outcomes[0]->sequence);
        self::assertSame('isconnected', $result->outcomes[0]->command);
        self::assertNull($result->outcomes[0]->variant);
        self::assertSame('Redis#0', $result->outcomes[0]->clientId);
        self::assertSame(0, $result->outcomes[0]->clientIndex);
        self::assertSame(\Redis::class, $result->outcomes[0]->clientClass);
        self::assertSame('normal', $result->outcomes[0]->operation);
        self::assertSame('same-slot', $result->outcomes[0]->slotPolicy);
        self::assertNotNull($result->outcomes[0]->replyType);
        self::assertNotNull($result->outcomes[0]->reply);
        self::assertGreaterThanOrEqual(0.0, $result->outcomes[0]->durationSeconds);
        $clients = $result->environment['clients'];
        self::assertIsArray($clients);
        $client = $clients[0] ?? null;
        self::assertIsArray($client);
        self::assertSame(\Redis::class, $client['class'] ?? null);
    }

    public function testFalseOnlyUnsupportedCommandIsExecutedButNotProblematic(): void
    {
        $result = (new Fuzzer())->run(
            [$this->falseGetClient(false)],
            new RunConfiguration(maxSteps: 3, seed: 42, commands: ['get']),
        );

        self::assertSame(3, $result->commands['get']['count']);
        self::assertSame(['false' => 3], $result->commands['get']['replies']);
        self::assertSame([], $result->problematicCommands);
    }

    public function testFalseOnlyServerSupportedCommandIsProblematic(): void
    {
        $result = (new Fuzzer())->run(
            [$this->falseGetClient(true)],
            new RunConfiguration(maxSteps: 3, seed: 42, commands: ['get']),
        );

        self::assertSame(
            ['get' => ['executions' => 3, 'false_replies' => 3]],
            $result->problematicCommands,
        );
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
        self::assertSame(1, array_sum($result->outcomes[0]->warnings));
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
        self::assertSame(\RuntimeException::class, $result->outcomes[0]->exception['class'] ?? null);
    }

    public function testClientThrownExceptionIsNotConvertedToFalse(): void
    {
        $client = new class extends \Redis {
            public function rawCommand(string $command, mixed ...$args): mixed
            {
                return [['get']];
            }

            public function get(string $key): mixed
            {
                throw new \RuntimeException('client method failed', 17);
            }

            public function getLastError(): ?string
            {
                return null;
            }

            public function getHost(): string
            {
                return 'test';
            }

            public function getPort(): int
            {
                return 0;
            }

            public function getOption(int $option): mixed
            {
                return 0;
            }
        };

        $result = (new Fuzzer())->run(
            [$client],
            new RunConfiguration(maxSteps: 1, seed: 42, commands: ['get']),
        );

        self::assertSame([], $result->commands['get']['replies']);
        self::assertSame(
            ['RuntimeException: client method failed' => 1],
            $result->commands['get']['exceptions'],
        );
        self::assertSame([
            'class' => \RuntimeException::class,
            'message' => 'client method failed',
            'code' => 17,
        ], $result->outcomes[0]->exception);
        self::assertNull($result->outcomes[0]->replyType);
        self::assertNull($result->outcomes[0]->reply);
    }

    public function testRedisErrorsAreRecordedAndMatchedByCatchPattern(): void
    {
        $client = new class extends \Redis {
            private ?string $lastError = null;

            public function rawCommand(string $command, mixed ...$args): mixed
            {
                return [['get']];
            }

            public function get(string $key): mixed
            {
                $this->lastError = 'WRONGTYPE synthetic client error';

                return false;
            }

            public function getLastError(): ?string
            {
                return $this->lastError;
            }

            public function clearLastError(): bool
            {
                $this->lastError = null;

                return true;
            }

            public function getHost(): string
            {
                return 'test';
            }

            public function getPort(): int
            {
                return 0;
            }

            public function getOption(int $option): mixed
            {
                return 0;
            }
        };

        $result = (new Fuzzer())->run(
            [$client],
            new RunConfiguration(
                maxSteps: 10,
                seed: 42,
                commands: ['get'],
                catchPattern: 'synthetic client error',
            ),
        );

        self::assertSame(1, $result->steps);
        self::assertSame(
            ['WRONGTYPE synthetic client error'],
            $result->outcomes[0]->redisErrors,
        );
        self::assertSame(
            'Redis error: WRONGTYPE synthetic client error',
            $result->caughtDiagnostic,
        );
        self::assertNull($client->getLastError());
    }

    public function testBinaryRepliesProduceBoundedJsonSafeSummaries(): void
    {
        $client = new class extends \Redis {
            public function rawCommand(string $command, mixed ...$args): mixed
            {
                return [['get']];
            }

            public function get(string $key): mixed
            {
                return str_repeat("\xff", 128);
            }

            public function getLastError(): ?string
            {
                return null;
            }

            public function getHost(): string
            {
                return 'test';
            }

            public function getPort(): int
            {
                return 0;
            }

            public function getOption(int $option): mixed
            {
                return 0;
            }
        };

        $result = (new Fuzzer())->run(
            [$client],
            new RunConfiguration(maxSteps: 1, seed: 42, commands: ['get']),
        );

        $reply = $result->outcomes[0]->reply;
        self::assertIsArray($reply);
        self::assertSame(128, $reply['length'] ?? null);
        self::assertSame(base64_encode(str_repeat("\xff", 96)), $reply['preview_base64'] ?? null);
        self::assertTrue($reply['truncated'] ?? false);
        self::assertStringContainsString(
            '"preview_base64"',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    private function falseGetClient(bool $supportsGet): \Redis
    {
        return new class ($supportsGet) extends \Redis {
            public function __construct(private bool $supportsGet)
            {
            }

            public function rawCommand(string $command, mixed ...$args): mixed
            {
                return [[$this->supportsGet ? 'get' : 'set']];
            }

            public function get(string $key): mixed
            {
                return false;
            }

            public function getLastError(): ?string
            {
                return null;
            }

            public function getHost(): string
            {
                return 'test';
            }

            public function getPort(): int
            {
                return 0;
            }

            public function getOption(int $option): mixed
            {
                return 0;
            }
        };
    }
}
