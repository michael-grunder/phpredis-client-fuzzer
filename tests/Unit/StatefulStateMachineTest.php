<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Stateful\CallableScenarioPeers;
use Mgrunder\PhpredisCommandFuzzer\Stateful\ScenarioPeers;
use Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulOutcome;
use Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulScenarioRegistry;
use Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulScenarioRunner;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\FakeStatefulRedis;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\FakeStatefulServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Server-free regressions for the reply-queue and recovery state machines.
 *
 * The fake client models a correct client, so every scenario passes against
 * it; each negative case breaks exactly one property and names the
 * postcondition that has to catch it.
 */
final class StatefulStateMachineTest extends TestCase
{
    private const SEED = 42;

    protected function setUp(): void
    {
        /* Scenarios record the PHP warnings a client raises, which is how the
           deliberate local rejection stays visible in the step list. */
        Command::resetCapturedWarnings();
    }

    protected function tearDown(): void
    {
        Command::finishCapturedWarnings();
    }

    /** @return iterable<string, array{string}> */
    public static function scenarioNames(): iterable
    {
        foreach (StatefulScenarioRegistry::names() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('scenarioNames')]
    public function testScenarioPassesAgainstACorrectClient(string $name): void
    {
        $outcome = $this->runScenario($name);

        self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
        self::assertNotEmpty($outcome->steps);
        self::assertNotEmpty($outcome->postconditions);
        self::assertNotContains(false, array_column($outcome->postconditions, 'passed'));
    }

    #[DataProvider('scenarioNames')]
    public function testScenarioIsRepeatableWithTheSameSeed(string $name): void
    {
        $first = $this->runScenario($name);
        $repeat = $this->runScenario($name);

        self::assertSame('passed', $repeat->status, $repeat->failure ?? '');
        self::assertSame(
            array_column($first->postconditions, 'name'),
            array_column($repeat->postconditions, 'name'),
        );
        self::assertSame(
            array_column($first->steps, 'operation'),
            array_column($repeat->steps, 'operation'),
        );
    }

    #[DataProvider('scenarioNames')]
    public function testScenarioRestoresAtomicModeEvenWhenEveryCallFails(string $name): void
    {
        $client = new FakeStatefulRedis();
        $outcome = $this->runOn(
            $name,
            $client,
            $this->intercept(static fn (): bool => false),
        );

        self::assertContains($outcome->status, ['failed', 'skipped'], $outcome->failure ?? '');
        self::assertSame(\Redis::ATOMIC, $client->getMode());
    }

    public function testTransactionMixedRepliesRequiresEveryQueuedReply(): void
    {
        foreach ([
            [true, 1, []],
            [false, true, 1],
            [false, true, 1, [], true],
            [false, true, '1', []],
            [false, true, 1.0, []],
            [true, false, 1, []],
            [1 => false, 2 => true, 3 => 1, 4 => []],
        ] as $index => $replies) {
            $outcome = $this->runOn(
                'transaction-mixed-replies',
                new FakeStatefulRedis(),
                $this->interceptReply('exec', $replies),
            );

            self::assertSame('failed', $outcome->status, "reply set {$index}");
            self::assertStringContainsString(
                'exec-returns-every-queued-reply-in-order',
                $outcome->failure ?? '',
                "reply set {$index}",
            );
        }
    }

    public function testCrossSlotVariantSpreadsKeysOverDistinctHashTags(): void
    {
        $sameSlot = new FakeStatefulRedis();
        $crossSlot = new FakeStatefulRedis();
        $this->runOn('transaction-mixed-replies', $sameSlot);
        $this->runOn('transaction-cross-slot-replies', $crossSlot);

        self::assertCount(1, $this->hashTags($sameSlot));
        self::assertCount(4, $this->hashTags($crossSlot));
    }

    public function testErrorAlignmentRequiresTheErrorInItsOwnPosition(): void
    {
        foreach ([
            [[true, true], 'exec-returns-one-reply-per-queued-command'],
            [[true, false, true, true], 'exec-returns-one-reply-per-queued-command'],
            [[false, false, true], 'successful-reply-before-the-error-is-aligned'],
            [[true, false, false], 'successful-reply-after-the-error-is-aligned'],
            [[true, true, true], 'error-occupies-the-failing-position'],
            [[true, 1, true], 'error-occupies-the-failing-position'],
        ] as [$replies, $condition]) {
            $outcome = $this->runOn(
                'transaction-error-alignment',
                new FakeStatefulRedis(),
                $this->interceptReply('exec', $replies),
            );

            self::assertSame('failed', $outcome->status, $condition);
            self::assertStringContainsString($condition, $outcome->failure ?? '', $condition);
        }
    }

    public function testErrorAlignmentAcceptsTheDocumentedErrorRepresentations(): void
    {
        foreach ([false, null, 'WRONGTYPE Operation against a key holding the wrong kind of value'] as $error) {
            $outcome = $this->runOn(
                'transaction-error-alignment',
                new FakeStatefulRedis(),
                $this->interceptReply('exec', [true, $error, true]),
            );

            self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
        }
    }

    public function testQueueErrorRequiresTheRejectionToReachTheCaller(): void
    {
        /* A client that acknowledges the queue-time error as a normal queue
           entry leaves the caller believing the command was queued. */
        $client = new FakeStatefulRedis();
        $outcome = $this->runOn(
            'transaction-queue-error',
            $client,
            $this->intercept(static fn ($subject, $method, $arguments): mixed => $method === 'rawCommand'
                ? $subject
                : (new StrictClientInvoker())->invoke($subject, $method, $arguments)),
        );

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('queue-time-error-is-reported-to-the-caller', $outcome->failure ?? '');
        self::assertSame(\Redis::ATOMIC, $client->getMode());
    }

    public function testQueueErrorRequiresAnAbortedTransaction(): void
    {
        $client = new FakeStatefulRedis();
        $client->abortsAfterQueueError = false;
        $outcome = $this->runOn('transaction-queue-error', $client);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('exec-aborts-the-transaction', $outcome->failure ?? '');
        self::assertSame(\Redis::ATOMIC, $client->getMode());
    }

    public function testQueueErrorRequiresTheAbortedWriteToBeDropped(): void
    {
        $outcome = $this->runOn(
            'transaction-queue-error',
            new FakeStatefulRedis(),
            $this->interceptMethod('get', 'queued-' . self::SEED, 2),
        );

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('aborted-transaction-applied-no-write', $outcome->failure ?? '');
    }

    public function testLocalRejectionIsSkippedWhenTheClientSendsTheCall(): void
    {
        $outcome = $this->runOn(
            'transaction-local-rejection',
            new FakeStatefulRedis(),
            $this->interceptMethod('sIntercard', 0),
        );

        self::assertSame('skipped', $outcome->status);
        self::assertStringContainsString('does not reject a negative SINTERCARD LIMIT', $outcome->failure ?? '');
    }

    public function testLocalRejectionRequiresNoQueueSlotForTheRejectedCall(): void
    {
        $client = new FakeStatefulRedis();
        $outcome = $this->runOn(
            'transaction-local-rejection',
            $client,
            $this->intercept(static function ($subject, $method, $arguments, $count): mixed {
                if ($method === 'sIntercard' && $count === 2) {
                    return $subject;
                }

                return (new StrictClientInvoker())->invoke($subject, $method, $arguments);
            }),
        );

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString(
            'rejected-call-does-not-acknowledge-queueing',
            $outcome->failure ?? '',
        );
    }

    public function testBothLocalRejectionsAreRecordedWithoutAServerError(): void
    {
        $outcome = $this->runOn('transaction-local-rejection', new FakeStatefulRedis());

        $rejections = array_values(array_filter(
            $outcome->steps,
            static fn ($step): bool => $step->operation === 'sIntercard(negative LIMIT)',
        ));
        self::assertCount(2, $rejections, 'the probe and the queued rejection are both recorded');
        foreach ($rejections as $rejection) {
            /* No Redis error is what separates a local rejection from a
               server rejection; the PHP warning the client raised is recorded
               by the run's warning collector. */
            self::assertSame([], $rejection->redisErrors);
            self::assertFalse($rejection->reply['value'] ?? null);
        }
    }

    public function testPeerOperationsAreRecordedInTheStepList(): void
    {
        $outcome = $this->runScenario('watch-abort');

        $operations = array_column($outcome->steps, 'operation');
        self::assertContains('peer:set', $operations);
        self::assertContains('peer:get', $operations);
        self::assertContains('watch', $operations);
    }

    public function testWatchScenariosAreSkippedWithoutAPeer(): void
    {
        foreach (['watch-abort', 'watch-unwatch-commit'] as $name) {
            $outcome = StatefulScenarioRegistry::create($name)
                ->run(new FakeStatefulRedis(), self::SEED, 0);

            self::assertSame('skipped', $outcome->status);
            self::assertStringContainsString('second connection', $outcome->failure ?? '');
        }
    }

    public function testWatchAbortDetectsAWatchThatWasNeverInstalled(): void
    {
        $outcome = $this->runOn(
            'watch-abort',
            new FakeStatefulRedis(),
            $this->interceptMethod('watch', true),
        );

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('exec-aborts-after-the-watched-key-changed', $outcome->failure ?? '');
    }

    public function testWatchUnwatchCommitDetectsAnUnwatchThatDidNothing(): void
    {
        $outcome = $this->runOn(
            'watch-unwatch-commit',
            new FakeStatefulRedis(),
            $this->interceptMethod('unwatch', true),
        );

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('exec-commits-after-unwatch', $outcome->failure ?? '');
    }

    public function testWatchScenarioIsSkippedWhenThePeerCannotSeeTheKeyspace(): void
    {
        $client = new FakeStatefulRedis();
        $peers = new CallableScenarioPeers(
            static fn (): FakeStatefulRedis => new FakeStatefulRedis(new FakeStatefulServer()),
        );
        $outcome = StatefulScenarioRegistry::create('watch-abort')
            ->run($client, self::SEED, 0, $peers);

        self::assertSame('skipped', $outcome->status);
        self::assertStringContainsString('does not observe the scenario key', $outcome->failure ?? '');
    }

    public function testPipelineScenarioIsSkippedWithoutPipelineSupport(): void
    {
        $outcome = $this->runOn(
            'pipeline-replies',
            new FakeStatefulRedis(),
            $this->intercept(static fn ($subject, $method, $arguments): mixed => $method === 'multi'
                ? (new StrictClientInvoker())->invoke($subject, $method, [])
                : (new StrictClientInvoker())->invoke($subject, $method, $arguments)),
        );

        self::assertSame('skipped', $outcome->status);
        self::assertStringContainsString('does not enter pipeline mode', $outcome->failure ?? '');
    }

    public function testPipelineScenarioRequiresAnEmptyPipelineToDrainEmpty(): void
    {
        $outcome = $this->runOn(
            'pipeline-replies',
            new FakeStatefulRedis(),
            $this->interceptReply('exec', [false, true, 1, []], 2),
        );

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('empty-pipeline-exec-returns-no-replies', $outcome->failure ?? '');
    }

    public function testQueuedValuesAreSerializedWhenTheyAreQueued(): void
    {
        $client = new FakeStatefulRedis(new FakeStatefulServer(), \Redis::SERIALIZER_PHP);
        self::assertSame('passed', $this->runOn('queued-value-mutation', $client)->status);

        $lazy = new FakeStatefulRedis(new FakeStatefulServer(), \Redis::SERIALIZER_PHP);
        $lazy->lazySerialization = true;
        $outcome = $this->runOn('queued-value-mutation', $lazy);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString(
            'committed-compound-value-is-the-value-that-was-queued',
            $outcome->failure ?? '',
        );
    }

    public function testQueuedValueScenarioSkipsTheCompoundCaseWithoutASerializer(): void
    {
        $outcome = $this->runOn('queued-value-mutation', new FakeStatefulRedis());

        self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
        self::assertNotContains(
            'committed-compound-value-is-the-value-that-was-queued',
            array_column($outcome->postconditions, 'name'),
        );
    }

    public function testRecoveryDetectsStaleRepliesFromTheDroppedQueue(): void
    {
        $client = new FakeStatefulRedis();
        $client->staleRepliesAfterClose = true;
        $outcome = $this->runOn('disconnect-recovery', $client);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('abandoned-first-write-was-not-applied', $outcome->failure ?? '');
    }

    public function testRecoveryDetectsATransactionModeThatSurvivesTheDisconnect(): void
    {
        $client = new FakeStatefulRedis();
        $client->keepModeOnClose = true;
        $outcome = $this->runOn('disconnect-recovery', $client);

        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('close-restores-atomic-mode', $outcome->failure ?? '');
        self::assertSame(\Redis::ATOMIC, $client->getMode());
    }

    public function testRecoveryIsSkippedWhenTheClientCannotBeUsedAgain(): void
    {
        $outcome = $this->runOn(
            'disconnect-recovery',
            new FakeStatefulRedis(),
            $this->intercept(static function ($subject, $method, $arguments): mixed {
                if ($method === 'ping') {
                    throw new \RedisException('Redis server went away');
                }

                return (new StrictClientInvoker())->invoke($subject, $method, $arguments);
            }),
        );

        self::assertSame('skipped', $outcome->status);
        self::assertStringContainsString('not usable after close()', $outcome->failure ?? '');
    }

    public function testScenarioPeersAreReusedPerClientAndReleasedAfterTheRun(): void
    {
        $server = new FakeStatefulServer();
        /** @var list<FakeStatefulRedis> $created */
        $created = [];
        $peers = new CallableScenarioPeers(static function () use ($server, &$created): FakeStatefulRedis {
            $peer = new FakeStatefulRedis($server);
            $created[] = $peer;

            return $peer;
        });
        $client = new FakeStatefulRedis($server);

        $outcomes = (new StatefulScenarioRunner(new StrictClientInvoker(), $peers))->run(
            [$client],
            ['watch-abort', 'watch-unwatch-commit'],
            self::SEED,
        );

        self::assertCount(2, $outcomes);
        foreach ($outcomes as $outcome) {
            self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
        }
        self::assertCount(1, $created, 'One peer is created per client, not per scenario');
        self::assertFalse($created[0]->isConnected(), 'The run closes the peers it opened');
    }

    public function testAPeerThatIsTheSameObjectIsRejected(): void
    {
        $client = new FakeStatefulRedis();
        $peers = new CallableScenarioPeers(static fn (): FakeStatefulRedis => $client);

        self::assertNull($peers->peerFor($client));
    }

    public function testAFailingPeerFactoryOnlySkipsTheScenario(): void
    {
        $peers = new CallableScenarioPeers(static function (): never {
            throw new \RuntimeException('no second connection available');
        });
        $outcome = StatefulScenarioRegistry::create('watch-abort')
            ->run(new FakeStatefulRedis(), self::SEED, 0, $peers);

        self::assertSame('skipped', $outcome->status);
        self::assertStringContainsString('second connection', $outcome->failure ?? '');
    }

    public function testEveryRegisteredScenarioNameIsCreatable(): void
    {
        foreach (StatefulScenarioRegistry::names() as $name) {
            self::assertSame($name, StatefulScenarioRegistry::create($name)->name());
        }
    }

    /** @return list<string> */
    private function hashTags(FakeStatefulRedis $client): array
    {
        $tags = [];
        foreach ($client->server()->touchedKeys() as $key) {
            if (preg_match('/\{([^}]*)\}/', $key, $matches) === 1) {
                $tags[$matches[1]] = true;
            }
        }

        return array_keys($tags);
    }

    private function runScenario(string $name): StatefulOutcome
    {
        return $this->runOn($name, new FakeStatefulRedis(new FakeStatefulServer(), \Redis::SERIALIZER_PHP));
    }

    private function runOn(
        string $name,
        FakeStatefulRedis $client,
        ?ClientInvoker $invoker = null,
    ): StatefulOutcome {
        return StatefulScenarioRegistry::create($name, $invoker ?? new StrictClientInvoker())
            ->run($client, self::SEED, 0, $this->peers($client));
    }

    private function peers(FakeStatefulRedis $client): ScenarioPeers
    {
        $serializer = $client->getOption(\Redis::OPT_SERIALIZER);

        return new CallableScenarioPeers(
            static fn (): FakeStatefulRedis => new FakeStatefulRedis(
                $client->server(),
                is_int($serializer) ? $serializer : \Redis::SERIALIZER_NONE,
            ),
        );
    }

    /**
     * Let the `$occurrence`-th call of `$method` run, then hand the caller
     * `$reply` instead of the real one, so only the reply differs.
     */
    private function interceptReply(string $method, mixed $reply, int $occurrence = 1): ClientInvoker
    {
        return $this->intercept(
            static function ($client, $called, $arguments, $count) use ($method, $reply, $occurrence): mixed {
                $actual = (new StrictClientInvoker())->invoke($client, $called, $arguments);

                return $called === $method && $count === $occurrence ? $reply : $actual;
            },
        );
    }

    /** Replace the `$occurrence`-th call of `$method` with `$reply`. */
    private function interceptMethod(string $method, mixed $reply, int $occurrence = 1): ClientInvoker
    {
        return $this->intercept(
            static function ($client, $called, $arguments, $count) use ($method, $reply, $occurrence): mixed {
                if ($called === $method && $count === $occurrence) {
                    return $reply;
                }

                return (new StrictClientInvoker())->invoke($client, $called, $arguments);
            },
        );
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
}
