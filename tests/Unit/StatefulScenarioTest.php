<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Stateful\TransactionScenario;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testScenariosCanReusePreviouslyPopulatedKeys(): void
    {
        $client = $this->client();
        foreach (range(1, 2) as $_) {
            foreach (StatefulScenarioRegistry::names() as $name) {
                $outcome = (new TransactionScenario($name))->run($client, 42, 0);
                self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
            }
        }
    }

    /** @return iterable<string, array{string, string, int, mixed, string}> */
    public static function rejectedReplies(): iterable
    {
        foreach (StatefulScenarioRegistry::names() as $scenario) {
            yield "{$scenario}: setup failed" => [$scenario, 'set', 1, false, 'setup-set-0-succeeds'];
            yield "{$scenario}: setup read failed" => [$scenario, 'get', 1, false, 'setup-key-0-has-initial-value'];
            yield "{$scenario}: multi failed" => [$scenario, 'multi', 1, false, 'multi-acknowledges-entry'];
            yield "{$scenario}: multi returned true" => [$scenario, 'multi', 1, true, 'multi-acknowledges-entry'];
            $queuedSet = $scenario === 'transaction-exec' ? 3 : 2;
            yield "{$scenario}: queue failed" => [$scenario, 'set', $queuedSet, false, 'set-0-is-queued'];
            yield "{$scenario}: queue returned true" => [$scenario, 'set', $queuedSet, true, 'set-0-is-queued'];
        }
        yield 'second queue failed' => ['transaction-exec', 'set', 4, false, 'set-1-is-queued'];
        yield 'watch failed' => ['watch-unwatch-discard', 'watch', 1, false, 'watch-succeeds'];
        yield 'unwatch failed' => ['watch-unwatch-discard', 'unwatch', 1, false, 'unwatch-succeeds'];
        foreach ([false, null, [], [true], [true, false], [false, true], [true, true, true], [1, 1], [1 => true, 2 => true]] as $index => $reply) {
            yield "exec invalid reply {$index}" => ['transaction-exec', 'exec', 1, $reply, 'exec-returns-two-successful-replies'];
        }
        foreach (['transaction-discard', 'watch-unwatch-discard'] as $scenario) {
            yield "{$scenario}: discard failed" => [$scenario, 'discard', 1, false, 'discard-succeeds'];
            yield "{$scenario}: discarded write persisted" => [$scenario, 'get', 2, 'state-42', 'discard-preserves-initial-value'];
        }
        yield 'first commit missing' => ['transaction-exec', 'get', 3, 'initial-42', 'exec-commits-key-0'];
        yield 'second commit missing' => ['transaction-exec', 'get', 4, 'initial-42', 'exec-commits-key-1'];
    }

    #[DataProvider('rejectedReplies')]
    public function testRejectsIncorrectReplies(
        string $scenario,
        string $method,
        int $occurrence,
        mixed $reply,
        string $condition,
    ): void {
        $invoker = $this->intercept(static function ($client, $called, $arguments, $count) use ($method, $occurrence, $reply): mixed {
            if ($called === $method && $count === $occurrence) {
                return $reply;
            }

            return (new StrictClientInvoker())->invoke($client, $called, $arguments);
        });
        $client = $this->client();
        $outcome = (new TransactionScenario($scenario, $invoker))->run($client, 42, 0);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString($condition, $outcome->failure ?? '');
        self::assertSame(\Redis::ATOMIC, $client->getMode());
        self::assertContains(false, array_column($outcome->postconditions, 'passed'));
    }

    public function testAllFalseOperationsCannotPass(): void
    {
        foreach (StatefulScenarioRegistry::names() as $scenario) {
            $outcome = (new TransactionScenario($scenario, $this->intercept(static fn (): bool => false)))
                ->run($this->client(), 42, 0);
            self::assertSame('failed', $outcome->status);
            self::assertStringContainsString('setup-set-0-succeeds', $outcome->failure ?? '');
        }
    }

    public function testStaleCommittedValuesCannotSatisfySetup(): void
    {
        $client = $this->client();
        self::assertSame('passed', (new TransactionScenario('transaction-exec'))->run($client, 42, 0)->status);
        $invoker = $this->intercept(static fn ($client, $method, $arguments): mixed => $method === 'set'
            ? true
            : (new StrictClientInvoker())->invoke($client, $method, $arguments));
        $outcome = (new TransactionScenario('transaction-exec', $invoker))->run($client, 42, 0);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('setup-key-0-has-initial-value', $outcome->failure ?? '');
    }

    public function testMultiMustActuallyChangeMode(): void
    {
        $invoker = $this->intercept(static fn ($client, $method, $arguments): mixed => $method === 'multi'
            ? $client
            : (new StrictClientInvoker())->invoke($client, $method, $arguments));
        $outcome = (new TransactionScenario('transaction-exec', $invoker))->run($this->client(), 42, 0);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('multi-enters-multi-mode', $outcome->failure ?? '');
    }

    public function testTerminalReplyDoesNotProveModeWasRestored(): void
    {
        $invoker = $this->intercept(static fn ($client, $method, $arguments): mixed => $method === 'exec'
            ? [true, true]
            : (new StrictClientInvoker())->invoke($client, $method, $arguments));
        $client = $this->client();
        $outcome = (new TransactionScenario('transaction-exec', $invoker))->run($client, 42, 0);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('terminal-operation-restores-atomic-mode', $outcome->failure ?? '');
        self::assertSame(['discard'], array_slice(array_column($outcome->steps, 'operation'), -1));
        self::assertSame(\Redis::ATOMIC, $client->getMode());
    }

    public function testCleanupFailureIsReportedAlongsideOriginalFailure(): void
    {
        $invoker = $this->intercept(static fn ($client, $method, $arguments): mixed => in_array($method, ['exec', 'discard'], true)
            ? false
            : (new StrictClientInvoker())->invoke($client, $method, $arguments));
        $outcome = (new TransactionScenario('transaction-exec', $invoker))->run($this->client(), 42, 0);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('exec-returns-two-successful-replies', $outcome->failure ?? '');
        self::assertStringContainsString('Cleanup failed:', $outcome->failure ?? '');
        self::assertStringContainsString('cleanup-discard-succeeds', $outcome->failure ?? '');
    }

    public function testExistingCallerTransactionIsPreserved(): void
    {
        $client = $this->client();
        $client->multi();
        $client->set('caller-owned-key', 'caller-owned-value');
        $outcome = (new TransactionScenario('transaction-discard'))->run($client, 42, 0);

        self::assertSame('failed', $outcome->status);
        self::assertSame([], $outcome->steps);
        self::assertSame(\Redis::MULTI, $client->getMode());
        self::assertSame([true], $client->exec());
        self::assertSame('caller-owned-value', $client->get('caller-owned-key'));
    }

    /**
     * @param \Closure(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster, string, list<mixed>, int): mixed $callback
     */
    private function intercept(\Closure $callback): ClientInvoker
    {
        return new class ($callback) implements ClientInvoker {
            /** @var array<string, int> */
            private array $counts = [];

            /** @param \Closure(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster, string, list<mixed>, int): mixed $callback */
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function invoke(
                \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $client,
                string $method,
                array $arguments,
            ): mixed {
                $count = $this->counts[$method] = ($this->counts[$method] ?? 0) + 1;

                return ($this->callback)($client, $method, $arguments, $count);
            }
        };
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

            public function multi(int $mode = \Redis::MULTI): \Redis
            {
                $this->queued = true;

                return $this;
            }

            /** @return array<mixed> */
            public function exec(): array
            {
                foreach ($this->pending as [$key, $method, $value]) {
                    if ($method === 'set') {
                        $this->values[$key] = $value;
                    }
                }
                $replies = array_fill(0, count($this->pending), true);
                $this->pending = [];
                $this->queued = false;

                return $replies;
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

                    return $this;
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
