<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Cli\OutputMode;
use Mgrunder\PhpredisCommandFuzzer\Cli\ResultFormatter;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulScenarioRegistry;
use Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulScenarioRunner;
use PHPUnit\Framework\TestCase;

final class StatefulScenarioTest extends TestCase
{
    protected function tearDown(): void
    {
        Command::finishCapturedWarnings();
    }

    public function testTransactionScenariosAreSeededAndLeaveExplicitPostconditions(): void
    {
        $client = $this->client();
        $outcomes = (new StatefulScenarioRunner())->run(
            [$client],
            ['transaction-exec', 'transaction-discard', 'watch-unwatch-discard'],
            42,
        );

        self::assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            self::assertSame('passed', $outcome->status);
            self::assertNotEmpty($outcome->steps);
            self::assertNotEmpty($outcome->postconditions);
            self::assertTrue(array_reduce(
                $outcome->postconditions,
                static fn (bool $passed, array $postcondition): bool => $passed && $postcondition['passed'],
                true,
            ));
        }
        $repeat = (new StatefulScenarioRunner())->run(
            [$this->client()],
            ['transaction-exec', 'transaction-discard', 'watch-unwatch-discard'],
            42,
        );
        self::assertSame(
            array_map(static fn ($outcome): string => $outcome->scenario, $outcomes),
            array_map(static fn ($outcome): string => $outcome->scenario, $repeat),
        );
    }

    public function testStatefulOutcomesAreIncludedInFuzzResults(): void
    {
        $result = (new Fuzzer())->run(
            [$this->client()],
            new RunConfiguration(
                maxSteps: 1,
                seed: 42,
                commands: ['isconnected'],
                includeLocal: true,
                scenarios: ['transaction-discard'],
            ),
        );

        self::assertCount(1, $result->statefulOutcomes);
        self::assertSame('transaction-discard', $result->statefulOutcomes[0]->scenario);
        self::assertTrue($result->statefulOutcomes[0]->passed());
        self::assertFalse($result->hasStatefulFailure());
        self::assertArrayHasKey('stateful_outcomes', $result->jsonSerialize());
        $output = (new ResultFormatter())->format($result, OutputMode::Detailed);
        self::assertStringContainsString('Stateful scenarios', $output);
        self::assertStringContainsString('transaction-discard/', $output);
        self::assertStringContainsString(': passed', $output);
    }

    public function testUnknownScenarioNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown stateful scenario');

        StatefulScenarioRegistry::select(['not-a-scenario']);
    }

    public function testRandomScenarioSelectionIsSeededAndCanSelectNone(): void
    {
        $first = StatefulScenarioRegistry::select(['random'], 42);
        $repeat = StatefulScenarioRegistry::select(['random'], 42);

        self::assertSame(
            array_map(static fn ($scenario): string => $scenario->name(), $first),
            array_map(static fn ($scenario): string => $scenario->name(), $repeat),
        );
        self::assertLessThanOrEqual(count(StatefulScenarioRegistry::names()), count($first));
        self::assertSame([], StatefulScenarioRegistry::select(['none'], 42));
    }

    /** @return \Redis */
    private function client(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, mixed> */
            private array $values = [];

            /** @var list<array{string, string, mixed}> */
            private array $pending = [];

            private bool $queued = false;

            public function rawCommand(string $command, mixed ...$arguments): mixed
            {
                return [['get']];
            }

            public function multi(int $mode = \Redis::MULTI): \Redis|bool
            {
                $this->queued = true;

                return true;
            }

            /** @return array<mixed> */
            public function exec(): array
            {
                foreach ($this->pending as [$key, $method, $value]) {
                    if ($method === 'set') {
                        $this->values[$key] = $value;
                    }
                }
                $this->pending = [];
                $this->queued = false;

                return [true];
            }

            public function discard(): \Redis|bool
            {
                $this->pending = [];
                $this->queued = false;

                return true;
            }

            /** @param array<int|string, mixed>|string $key */
            public function watch(array|string $key, string ...$other_keys): \Redis|bool
            {
                return true;
            }

            public function unwatch(): \Redis|bool
            {
                return true;
            }

            /** @param array<int|string, mixed>|null $options */
            public function set(string $key, mixed $value, mixed $options = null): \Redis|bool
            {
                if ($this->queued) {
                    $this->pending[] = [$key, 'set', $value];
                } else {
                    $this->values[$key] = $value;
                }

                return true;
            }

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? false;
            }

            public function getMode(): int
            {
                return $this->queued ? \Redis::MULTI : \Redis::ATOMIC;
            }

            public function getLastError(): ?string
            {
                return null;
            }

            public function clearLastError(): bool
            {
                return true;
            }

            public function getHost(): string
            {
                return 'stateful-test';
            }

            public function getPort(): int
            {
                return 6379;
            }

            public function getOption(int $option): mixed
            {
                return 0;
            }

            public function isConnected(): bool
            {
                return true;
            }
        };
    }
}
