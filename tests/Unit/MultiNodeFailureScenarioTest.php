<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\ClientInvoker;
use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Stateful\CallableScenarioPeers;
use Mgrunder\RedisClientTester\Stateful\StatefulScenarioRegistry;
use Mgrunder\RedisClientTester\Tests\Fixtures\FakeStatefulRedis;
use Mgrunder\RedisClientTester\Tests\Fixtures\FakeStatefulServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class MultiNodeFailureScenarioTest extends TestCase
{
    protected function tearDown(): void
    {
        Command::finishCapturedWarnings();
    }

    /** @return iterable<string, array{string, class-string<RedisCluster|Cluster>}> */
    public static function scenarios(): iterable
    {
        foreach (['cluster-queue-error', 'cluster-watch-abort', 'cluster-error-alignment'] as $name) {
            foreach ([RedisCluster::class, Cluster::class] as $class) {
                yield "{$name}-{$class}" => [$name, $class];
            }
        }
    }

    /** @param class-string<RedisCluster|Cluster> $class */
    #[DataProvider('scenarios')]
    public function testBothFailurePositionsAndRecoveryAreRepeatable(string $name, string $class): void
    {
        [$client, $peer, $model] = $this->clients($class);
        $peers = new CallableScenarioPeers(static fn () => $peer);
        $scenario = StatefulScenarioRegistry::create($name, $model);
        $first = $scenario->run($client, 42, 0, $peers);
        self::assertSame('passed', $first->status, $first->failure ?? '');
        self::assertSame(4, $model->execs);
        self::assertSame(Redis::ATOMIC, $model->mode);
        self::assertContains('failure-on-node-1:recovery-value-1', array_column($first->postconditions, 'name'));
        self::assertContains('keys-route-to-distinct-primaries', array_column($first->postconditions, 'name'));
        self::assertNotEmpty($first->steps[0]->argumentCode);
        $repeat = $scenario->run($client, 42, 0, $peers);
        self::assertSame('passed', $repeat->status, $repeat->failure ?? '');
        self::assertSame(array_column($first->steps, 'argumentCode'), array_column($repeat->steps, 'argumentCode'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function corruptions(): iterable
    {
        yield 'missing reply' => ['cluster-watch-abort', 'drop', 'exec-reply-order'];
        yield 'reordered reply' => ['cluster-watch-abort', 'reverse', 'exec-reply-order'];
        yield 'false success' => ['cluster-watch-abort', 'success', 'exec-reply-order'];
        yield 'wrong error type' => ['cluster-error-alignment', 'wrong-error', 'wrongtype-in-position-2'];
        yield 'unrelated exception' => ['cluster-queue-error', 'transport', 'exec-reports-abort'];
        yield 'abort write leaked' => ['cluster-queue-error', 'leak', 'stored-value-0'];
        yield 'stale recovery reply' => ['cluster-watch-abort', 'stale', 'recovery-reply-order'];
        yield 'mode not reset' => ['cluster-queue-error', 'mode', 'exec-restores-atomic'];
        yield 'local rejection' => ['cluster-queue-error', 'local', 'server-rejects-command'];
    }

    #[DataProvider('corruptions')]
    public function testClientDefectsAreDetected(string $name, string $corrupt, string $condition): void
    {
        [$client, $peer, $model] = $this->clients(RedisCluster::class);
        $model->corrupt = $corrupt;
        $outcome = StatefulScenarioRegistry::create($name, $model)->run(
            $client, 42, 0, new CallableScenarioPeers(static fn () => $peer),
        );
        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString($condition, $outcome->failure ?? '');
        self::assertSame(Redis::ATOMIC, $model->mode, 'cleanup must restore the client');
    }

    public function testOriginalWatchAbortAcceptsClusterReplyShape(): void
    {
        [$client, $peer, $model] = $this->clients(RedisCluster::class);
        $outcome = StatefulScenarioRegistry::create('watch-abort', $model)->run(
            $client, 42, 0, new CallableScenarioPeers(static fn () => $peer),
        );
        self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
    }

    public function testRelayThrowOnErrorKeepsExactDataAndRecoveryChecks(): void
    {
        foreach (['cluster-watch-abort', 'cluster-queue-error'] as $name) {
            [$client, $peer, $model] = $this->clients(Cluster::class);
            $model->throwOnError = true;
            $outcome = StatefulScenarioRegistry::create($name, $model)->run(
                $client, 42, 0, new CallableScenarioPeers(static fn () => $peer),
            );
            self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
            self::assertSame(4, $model->execs);
        }
    }

    public function testSameNodeAndUnavailableDiscoverySkipBeforeWrites(): void
    {
        foreach (['single-node', 'discovery-error'] as $corrupt) {
            [$client, , $model] = $this->clients(RedisCluster::class);
            $model->corrupt = $corrupt;
            $outcome = StatefulScenarioRegistry::create('cluster-queue-error', $model)->run($client, 42, 0);
            self::assertSame('skipped', $outcome->status);
            self::assertSame(0, $model->writes);
            self::assertCount($corrupt === 'single-node' ? 128 : 1, $outcome->steps);
        }
    }

    public function testStandaloneAndMissingPeerSkip(): void
    {
        $client = new FakeStatefulRedis();
        foreach (['cluster-queue-error', 'cluster-watch-abort', 'cluster-error-alignment'] as $name) {
            $outcome = StatefulScenarioRegistry::create($name)->run($client, 42, 0);
            self::assertSame('skipped', $outcome->status);
            self::assertSame([], $outcome->steps);
        }
        [$cluster, , $model] = $this->clients(RedisCluster::class);
        $outcome = StatefulScenarioRegistry::create('cluster-watch-abort', $model)->run($cluster, 42, 0);
        self::assertSame('skipped', $outcome->status);
        self::assertSame(0, $model->writes);
    }

    public function testDiscoveryTransportFailureIsNotACapabilitySkip(): void
    {
        [$client, , $model] = $this->clients(RedisCluster::class);
        $model->corrupt = 'discovery-timeout';
        $outcome = StatefulScenarioRegistry::create('cluster-queue-error', $model)->run($client, 42, 0);
        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('node-identity-is-valid', $outcome->failure ?? '');
        self::assertSame(0, $model->writes);
    }

    public function testCallerTransactionIsPreserved(): void
    {
        [$client, , $model] = $this->clients(RedisCluster::class);
        $model->mode = Redis::MULTI;
        $outcome = StatefulScenarioRegistry::create('cluster-queue-error', $model)->run($client, 42, 0);
        self::assertSame('failed', $outcome->status);
        self::assertSame(Redis::MULTI, $model->mode);
        self::assertSame([], $outcome->steps);
    }

    /**
     * @param class-string<RedisCluster|Cluster> $class
     * @return array{RedisCluster|Cluster, RedisCluster|Cluster, MultiNodeFixtureInvoker}
     */
    private function clients(string $class): array
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Optional client extension is unavailable');
        }
        $model = new MultiNodeFixtureInvoker();
        $client = $this->createStub($class);
        $peer = $this->createStub($class);
        $model->peer = $peer;
        $client->method('getMode')->willReturnCallback(static fn () => $model->mode);
        $client->method('getLastError')->willReturnCallback(static fn () => $model->error);
        $client->method('clearLastError')->willReturnCallback(static function () use ($model): bool {
            $model->error = null;
            return true;
        });
        $peer->method('getMode')->willReturn(Redis::ATOMIC);
        return [$client, $peer, $model];
    }
}

/** Independent per-node transaction queues backed by the existing fake Redis servers. */
final class MultiNodeFixtureInvoker implements ClientInvoker
{
    public int $mode = Redis::ATOMIC;
    public int $execs = 0;
    public int $writes = 0;
    public ?string $error = null;
    public ?string $corrupt = null;
    public bool $throwOnError = false;
    public RedisCluster|Cluster|null $peer = null;
    /** @var array<int, FakeStatefulRedis> */
    private array $nodes = [];
    /** @var array<int, FakeStatefulRedis> */
    private array $peers = [];
    /** @var list<int> */
    private array $queue = [];
    /** @var array<int, true> */
    private array $started = [];
    private ?int $dirty = null;
    private ?string $dirtyKey = null;

    public function __construct()
    {
        foreach ([0, 1] as $node) {
            $server = new FakeStatefulServer();
            $this->nodes[$node] = new FakeStatefulRedis($server);
            $this->peers[$node] = new FakeStatefulRedis($server);
        }
    }

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        if ($method === 'getOption') {
            return $this->throwOnError;
        }
        if ($method === 'multi') {
            $this->mode = Redis::MULTI;
            return $client;
        }
        if ($method === 'exec') {
            return $this->execute($client);
        }
        if ($method === 'discard') {
            foreach ($this->nodes as $node) {
                if ($node->getMode() === Redis::MULTI) {
                    $node->discard();
                }
            }
            $this->reset();
            return true;
        }
        $key = is_string($arguments[0] ?? null) ? $arguments[0] : '';
        preg_match('/\{(\d+)\}/', $key, $matches);
        $index = (int) ($matches[1] ?? 0) % 3 === 2 ? 1 : 0;
        if ($method === 'rawCommand' && ($arguments[1] ?? '') === 'CLUSTER') {
            if ($this->corrupt === 'discovery-timeout') {
                throw new \RedisClusterException('read timeout');
            }
            if ($this->corrupt === 'discovery-error') {
                throw new \RuntimeException('NOPERM');
            }
            return str_repeat($this->corrupt === 'single-node' || $index === 0 ? 'a' : 'b', 40);
        }
        if ($method === 'rawCommand') {
            if ($this->corrupt === 'local') {
                return false;
            }
            $this->nodes[$index]->rawCommand('get');
            $this->dirty = $index;
            $this->dirtyKey = $key;
            $this->error = 'ERR unknown command';
            return false;
        }
        $node = ($client === $this->peer ? $this->peers : $this->nodes)[$index];
        if ($method === 'set') {
            $this->writes++;
        }
        if ($this->mode === Redis::MULTI && $client !== $this->peer) {
            if (!isset($this->started[$index])) {
                $node->multi();
                $this->started[$index] = true;
            }
            $this->queue[] = $index;
            $node->$method(...$arguments);
            return $client;
        }
        return $method === 'ping' ? $node->ping() : $node->$method(...$arguments);
    }

    private function execute(Redis|RedisCluster|Relay|Cluster $client): mixed
    {
        $this->execs++;
        $results = [];
        $aborted = false;
        foreach ($this->started as $index => $_) {
            $node = $this->nodes[$index];
            if ($aborted) {
                $node->discard();
                continue;
            }
            $results[$index] = $node->exec();
            if ($index === $this->dirty && $client instanceof RedisCluster) {
                $aborted = true;
            }
        }
        $positions = [0, 0];
        $reply = [];
        foreach ($this->queue as $index) {
            $nodeReplies = $results[$index] ?? false;
            $reply[] = is_array($nodeReplies) ? $nodeReplies[$positions[$index]++] : false;
        }
        if ($this->corrupt === 'leak' && $this->dirtyKey !== null) {
            $this->nodes[$this->dirty ?? 0]->set($this->dirtyKey, 'leaked');
        }
        $relayAbort = $this->throwOnError && in_array(false, $results, true);
        $dirty = $this->dirty !== null;
        $this->reset();
        if ($this->corrupt === 'mode') {
            $this->mode = Redis::MULTI;
        }
        if ($this->corrupt === 'transport') {
            throw new \RuntimeException('read timeout');
        }
        if ($aborted) {
            throw new \RedisClusterException('Error processing EXEC across the cluster');
        }
        if ($relayAbort) {
            throw new \Relay\Exception($dirty ? 'EXECABORT Transaction discarded' : 'Transaction failed');
        }
        return match ($this->corrupt) {
            'drop' => array_slice($reply, 1),
            'reverse' => array_reverse($reply),
            'success' => array_fill(0, count($reply), true),
            'wrong-error' => array_replace($reply, [2 => new \RuntimeException('read timeout')]),
            'stale' => $this->execs === 2 ? [false, false, false, false] : $reply,
            default => $reply,
        };
    }

    private function reset(): void
    {
        $this->mode = Redis::ATOMIC;
        $this->queue = [];
        $this->started = [];
        $this->dirty = null;
        $this->dirtyKey = null;
    }
}
