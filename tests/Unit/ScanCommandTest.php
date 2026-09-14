<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\ScanCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\ScanCursor;
use Mgrunder\PhpredisCommandFuzzer\Hooks\CompletedInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationRejected;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;
use Mgrunder\PhpredisCommandFuzzer\InvocationMode;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\InvocationTrace;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\RecordingScanRedis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class ScanProbe extends ScanCommand
{
    public string $variant = 'iterate';

    public function __construct(private readonly string $command = 'scan') {}

    public function name(): string { return $this->command; }
    public function type(): string { return self::ANY; }
    protected function chooseVariant(): string { return $this->variant; }

    protected function initArgs(FuzzConfig $config): void
    {
        if ($this->command !== 'scan') {
            $this->key = $config->getRandomKey(self::HASH);
        }
        $this->pattern = '*';
        $this->type = $this->command === 'scan' ? 'hash' : null;
    }
}

final class ScanRecordingInvoker implements ClientInvoker
{
    /** @var list<array{client: Redis|RedisCluster|Relay|Cluster, method: string, args: list<mixed>}> */
    public array $calls = [];
    public int|string|null $nextCursor = 123;
    /** @var list<string>|false */
    public array|false $reply = [];
    public mixed $rawReply = ['123', []];
    public string $prefix = '';
    public int $database = 0;
    public int $scanOption = Redis::SCAN_NORETRY;
    public string $host = 'fake';
    public int $port = 6379;
    public bool $connected = true;
    public int $mode = Redis::ATOMIC;
    public bool $throw = false;
    public bool $contextThrows = false;
    public bool $modeThrows = false;

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        if ($method === 'getMode' && $this->modeThrows) {
            throw new \RuntimeException('mode unavailable');
        }
        if ($method === 'getOption') {
            if ($this->contextThrows) {
                throw new \RuntimeException('context unavailable');
            }
            return $arguments[0] === Redis::OPT_PREFIX ? $this->prefix : $this->scanOption;
        }
        if (in_array($method, ['getMode', 'getDBNum', 'getHost', 'getPort', 'isConnected'], true)) {
            return match ($method) {
                'getMode' => $this->mode,
                'getDBNum' => $this->database,
                'getHost' => $this->host,
                'getPort' => $this->port,
                'isConnected' => $this->connected,
            };
        }
        // Snapshot scalars instead of retaining the live cursor reference.
        $snapshot = [];
        foreach ($arguments as $argument) {
            $snapshot[] = $argument;
        }
        $this->calls[] = ['client' => $client, 'method' => $method, 'args' => $snapshot];
        if ($method !== 'rawCommand') {
            $arguments[$method === 'scan' ? 0 : 1] = $this->nextCursor;
        }
        if ($this->throw) {
            throw new \TypeError('synthetic scan failure');
        }
        return $method === 'rawCommand' ? $this->rawReply : $this->reply;
    }
}

final class ScanCommandTest extends TestCase
{
    protected function setUp(): void { mt_srand(42); }

    protected function tearDown(): void
    {
        Command::setLifecycleHooks(null);
        Command::finishCapturedWarnings();
        ScriptLogger::finish();
        InvocationTrace::$calls = [];
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function surfaces(): iterable
    {
        foreach ([Redis::class, RedisCluster::class, Relay::class, Cluster::class] as $class) {
            foreach (['scan', 'hscan', 'sscan', 'zscan'] as $name) {
                foreach ([false, true] as $raw) {
                    yield $class . '/' . $name . '/' . ($raw ? 'raw' : 'method') => [$class, $name, $raw];
                }
            }
        }
    }

    private function client(string $class): Redis|RedisCluster|Relay|Cluster
    {
        if (!class_exists($class)) {
            self::markTestSkipped($class . ' is not installed');
        }
        return match ($class) {
            Redis::class => new RecordingScanRedis(),
            RedisCluster::class => new class extends RedisCluster {
                public function __construct() {}
                public function getLastError(): ?string { return null; }
            },
            Relay::class => new class extends Relay {
                public function getLastError(): ?string { return null; }
            },
            Cluster::class => new class extends Cluster {
                public function __construct() {}
                public function getLastError(): ?string { return null; }
            },
            default => throw new \LogicException('Unknown test client'),
        };
    }

    private function cursorIndex(string $class, string $name, bool $raw): int
    {
        return ($name === 'scan' ? 0 : 1) + ($raw ? 1 : 0)
            + ($raw && in_array($class, [RedisCluster::class, Cluster::class], true) ? 1 : 0);
    }

    #[DataProvider('surfaces')]
    public function testEachClientContinuesItsOwnIterationAcrossEmptyBatches(string $class, string $name, bool $raw): void
    {
        $first = $this->client($class);
        $second = $this->client($class);
        $command = new ScanProbe($name);
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $config = (new FuzzConfig())->setCluster(true)->setShards(8);
        $method = $raw ? 'fuzzRaw' : 'fuzz';
        $index = $this->cursorIndex($class, $name, $raw);
        $config->beginCommand($command, $raw);
        $command->$method($first, $config);
        $invoker->nextCursor = '9223372036854775808';
        $invoker->rawReply = ['9223372036854775808', []];
        $config->beginCommand($command, $raw);
        $command->$method($second, $config);
        $config->beginCommand($command, $raw);
        $command->$method($first, $config);
        $command->$method($second, $config);
        self::assertSame($raw ? 0 : null, $invoker->calls[0]['args'][$index]);
        self::assertSame($raw ? 0 : null, $invoker->calls[1]['args'][$index]);
        self::assertSame($raw ? '123' : 123, $invoker->calls[2]['args'][$index]);
        self::assertSame('9223372036854775808', $invoker->calls[3]['args'][$index]);
        // Key, pattern, TYPE and cluster routing stay attached to their cursor.
        $initial = $invoker->calls[0]['args'];
        $continued = $invoker->calls[2]['args'];
        unset($initial[$index], $continued[$index]);
        if (!$raw) {
            unset($initial[$name === 'scan' && $class !== Redis::class && $class !== Relay::class ? 3 : ($name === 'scan' ? 2 : 3)]);
            unset($continued[$name === 'scan' && $class !== Redis::class && $class !== Relay::class ? 3 : ($name === 'scan' ? 2 : 3)]);
            self::assertSame($initial, $continued);
        } elseif (in_array($class, [RedisCluster::class, Cluster::class], true)) {
            self::assertSame($initial[0], $continued[0]);
        }
    }

    /** @return iterable<string, array{int|string|null}> */
    public static function completedCursors(): iterable
    {
        yield 'null' => [null];
        yield 'int zero' => [0];
        yield 'string zero' => ['0'];
    }

    #[DataProvider('completedCursors')]
    public function testCompletionRestartsWithNull(int|string|null $end): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $config = new FuzzConfig();
        $client->nextCursor = $end;
        $command->fuzz($client, $config);
        $command->fuzz($client, $config);
        self::assertSame([null, null], array_column($client->scans, 'cursor'));
    }

    /** @return iterable<string, array{string}> */
    public static function contextChanges(): iterable
    {
        foreach (['prefix', 'database', 'scanOption', 'host', 'port', 'connected', 'mode', 'config', 'keyspace', 'contextThrows'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('contextChanges')]
    public function testContextChangesDiscardOldCursors(string $change): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        $command->fuzz($client, $config);
        match ($change) {
            'prefix' => $invoker->prefix = 'other:',
            'database' => $invoker->database = 1,
            'scanOption' => $invoker->scanOption = Redis::SCAN_RETRY,
            'host' => $invoker->host = 'other',
            'port' => $invoker->port = 6380,
            'connected' => $invoker->connected = false,
            'mode' => $invoker->mode = Redis::MULTI,
            'config' => $config = new FuzzConfig(),
            'keyspace' => $config->setKeys(20),
            'contextThrows' => $invoker->contextThrows = true,
            default => throw new \LogicException('Unknown change'),
        };
        $command->fuzz($client, $config);
        $invoker->mode = Redis::ATOMIC;
        $invoker->connected = true;
        $invoker->contextThrows = false;
        $command->fuzz($client, $config);
        self::assertNull($invoker->calls[1]['args'][0]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cursorVariants(): iterable
    {
        foreach (ScanCursor::VARIANTS as $variant) {
            yield $variant => [$variant, false];
            yield $variant . '/raw' => [$variant, true];
        }
    }

    #[DataProvider('cursorVariants')]
    public function testFiniteCursorCorpusDoesNotPolluteOrdinaryIteration(string $variant, bool $raw): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        $method = $raw ? 'fuzzRaw' : 'fuzz';
        $index = $raw ? 1 : 0;
        $command->$method($client, $config);
        $command->variant = $variant;
        $invoker->nextCursor = 456;
        $invoker->rawReply = ['456', []];
        $command->$method($client, $config);
        $command->variant = 'iterate';
        $command->$method($client, $config);
        $expected = ScanCursor::value($variant);
        self::assertSame($raw ? ($expected ?? 0) : $expected, $invoker->calls[1]['args'][$index]);
        self::assertSame($raw ? '123' : 123, $invoker->calls[2]['args'][$index]);
    }

    public function testEarlyAbandonmentAndRawStateAreIndependent(): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe('hscan');
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        $command->fuzz($client, $config);
        $command->fuzzRaw($client, $config);
        $command->fuzz($client, $config);
        $command->variant = 'restart';
        $command->fuzz($client, $config);
        $command->variant = 'iterate';
        $command->fuzzRaw($client, $config);
        self::assertSame(123, $invoker->calls[2]['args'][1]);
        self::assertNull($invoker->calls[3]['args'][1]);
        self::assertSame('123', $invoker->calls[4]['args'][2]);
    }

    /** @return iterable<string, array{InvocationMode}> */
    public static function modes(): iterable
    {
        yield 'strict' => [InvocationMode::Strict];
        yield 'coercive' => [InvocationMode::Coercive];
    }

    #[DataProvider('modes')]
    public function testReplacementCursorReachesClientHooksAndContinuation(InvocationMode $mode): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $command->setClientInvoker($mode->invoker());
        $hooks = new HookRegistry();
        $hooks->add('replace-cursor', new class implements InvocationHook {
            public function beforeInvocation(PendingInvocation $invocation): HookDecision
            {
                $args = $invocation->arguments;
                $args[0] = '9223372036854775808';
                return HookDecision::replace($args);
            }
        });
        $pre = [];
        $post = [];
        $hooks->onPreCommand('pre', function (PendingInvocation $event) use (&$pre): void {
            $pre[] = $event->arguments[0];
        });
        $hooks->onPostCommand('post', function (CompletedInvocation $event) use (&$post): void {
            $post[] = $event->arguments[0];
        });
        Command::setLifecycleHooks($hooks);
        $command->setInvocationHook($hooks);
        $config = new FuzzConfig();
        $command->fuzz($client, $config);
        $command->setInvocationHook(null);
        $command->fuzz($client, $config);
        self::assertSame(['9223372036854775808', 123], array_column($client->scans, 'cursor'));
        self::assertSame(['9223372036854775808', 123], $pre);
        self::assertSame([123, 123], $post);
    }

    public function testThrowingScanIsObservedAndNextCallStartsFresh(): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $config = new FuzzConfig();
        $hooks = new HookRegistry();
        $post = [];
        $hooks->onPostCommand('record', function (CompletedInvocation $event) use (&$post): void { $post[] = $event; });
        Command::setLifecycleHooks($hooks);
        $command->fuzz($client, $config);
        $client->throwOnScan = true;
        try {
            $command->fuzz($client, $config);
            self::fail('Expected scan exception');
        } catch (\TypeError $exception) {
            self::assertSame('synthetic scan failure', $exception->getMessage());
        }
        $client->throwOnScan = false;
        $command->fuzz($client, $config);
        self::assertSame([null, 123, null], array_column($client->scans, 'cursor'));
        self::assertInstanceOf(\TypeError::class, $post[1]->throwable);
    }

    public function testRejectionDoesNotDispatchScan(): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $hooks = new HookRegistry();
        $hooks->add('reject', new class implements InvocationHook {
            public function beforeInvocation(PendingInvocation $invocation): HookDecision { return HookDecision::reject('test'); }
        });
        $command->setInvocationHook($hooks);
        try {
            $command->fuzz($client, new FuzzConfig());
            self::fail('Expected rejection');
        } catch (InvocationRejected) {
            self::assertSame([], $client->scans);
        }
    }

    public function testFalseEmptyBatchContinuesButRedisErrorResets(): void
    {
        $client = new RecordingScanRedis();
        $client->scanReply = false;
        $command = new ScanProbe();
        $config = new FuzzConfig();
        self::assertFalse($command->fuzz($client, $config));
        $client->scanError = 'WRONGTYPE synthetic scan error';
        Command::beginInvocation();
        self::assertFalse($command->fuzz($client, $config));
        self::assertSame(['WRONGTYPE synthetic scan error'], Command::finishInvocationRedisErrors());
        $command->fuzz($client, $config);
        self::assertSame([null, 123, null], array_column($client->scans, 'cursor'));
        self::assertNull($client->getLastError());
    }

    /** @return iterable<string, array{mixed}> */
    public static function rawFailures(): iterable
    {
        yield 'error' => [false];
        yield 'missing payload' => [['123']];
        yield 'non-array payload' => [['123', false]];
        yield 'extra element' => [['123', [], []]];
        yield 'bad cursor type' => [[[123], []]];
        yield 'invalid cursor' => [['not-a-cursor', []]];
        yield 'negative cursor' => [[-1, []]];
        yield 'overflow cursor' => [['18446744073709551616', []]];
        yield 'finished' => [['0', []]];
    }

    #[DataProvider('rawFailures')]
    public function testMalformedOrCompletedRawReplyStartsFresh(mixed $reply): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        $command->fuzzRaw($client, $config);
        $invoker->rawReply = $reply;
        self::assertSame($reply, $command->fuzzRaw($client, $config));
        $command->fuzzRaw($client, $config);
        self::assertSame([0, '123', 0], array_map(static fn (array $call): mixed => $call['args'][1], $invoker->calls));
    }

    public function testChangedPatternDoesNotKeepItsCursorUnderTheOldPattern(): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $config = new FuzzConfig();
        $command->fuzz($client, $config);
        $hooks = new HookRegistry();
        $hooks->add('pattern', new class implements InvocationHook {
            public function beforeInvocation(PendingInvocation $invocation): HookDecision
            {
                $args = $invocation->arguments;
                $args[1] = 'changed:*';
                return HookDecision::replace(array_values($args));
            }
        });
        $command->setInvocationHook($hooks);
        $command->fuzz($client, $config);
        $command->setInvocationHook(null);
        $command->fuzz($client, $config);
        self::assertSame([null, 123, null], array_column($client->scans, 'cursor'));
        self::assertSame(['*', 'changed:*', '*'], array_column($client->scans, 'pattern'));
    }

    public function testClientObjectsAreNotRetainedByIterationState(): void
    {
        $client = new RecordingScanRedis();
        $weak = \WeakReference::create($client);
        $command = new ScanProbe();
        $command->fuzz($client, new FuzzConfig());
        unset($client);
        self::assertNull($weak->get());
    }

    public function testContextChangedByHookInvalidatesTheCursor(): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $hooks = new HookRegistry();
        $hooks->onPreCommand('change-database', static function (PendingInvocation $event) use ($invoker): void {
            $invoker->database++;
        });
        Command::setLifecycleHooks($hooks);
        $config = new FuzzConfig();
        $command->fuzz($client, $config);
        Command::setLifecycleHooks(null);
        $command->fuzz($client, $config);
        self::assertNull($invoker->calls[1]['args'][0]);
    }

    public function testModeFailureDiscardsBothSurfacesAndRemainsAnException(): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        $command->fuzz($client, $config);
        $command->fuzzRaw($client, $config);
        $invoker->modeThrows = true;
        try {
            $command->fuzz($client, $config);
            self::fail('Expected mode exception');
        } catch (\RuntimeException $exception) {
            self::assertSame('mode unavailable', $exception->getMessage());
        }
        $invoker->modeThrows = false;
        $command->fuzz($client, $config);
        $command->fuzzRaw($client, $config);
        self::assertNull($invoker->calls[2]['args'][0]);
        self::assertSame(0, $invoker->calls[3]['args'][1]);
    }

    public function testChangingDataAndRetryOptionKeepEmptyBatchSemantics(): void
    {
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $invoker = new ScanRecordingInvoker();
        $command->setClientInvoker($invoker);
        $config = new FuzzConfig();
        foreach ([Redis::SCAN_NORETRY, Redis::SCAN_RETRY] as $option) {
            $invoker->scanOption = $option;
            $invoker->reply = false;
            $invoker->nextCursor = '18446744073709551615';
            self::assertFalse($command->fuzz($client, $config));
            $invoker->reply = ['newly-inserted-member'];
            $invoker->nextCursor = 0;
            self::assertSame(['newly-inserted-member'], $command->fuzz($client, $config));
        }
        self::assertSame([null, '18446744073709551615', null, '18446744073709551615'],
            array_map(static fn (array $call): mixed => $call['args'][0], $invoker->calls));
    }

    public function testRandomSchedulingIsSeededAndBounded(): void
    {
        $traces = [];
        foreach ([1, 2] as $run) {
            mt_srand(42);
            $client = new RecordingScanRedis();
            $command = new \Mgrunder\PhpredisCommandFuzzer\Commands\Command\scan();
            $invoker = new ScanRecordingInvoker();
            $command->setClientInvoker($invoker);
            $config = (new FuzzConfig())->setMaxKeys(3);
            for ($step = 0; $step < 200; $step++) {
                $command->fuzz($client, $config);
            }
            $traces[$run] = array_column($invoker->calls, 'args');
            foreach ($traces[$run] as $args) {
                self::assertIsInt($args[2]);
                self::assertGreaterThanOrEqual(0, $args[2]);
                self::assertLessThanOrEqual(3, $args[2]);
            }
            self::assertCount(200, $traces[$run]);
            self::assertGreaterThan(150, count(array_filter($traces[$run], static fn (array $args): bool => $args[0] === 123)));
        }
        self::assertSame($traces[1], $traces[2]);
    }

    #[DataProvider('modes')]
    public function testExecutableReplayPreservesCursorInputs(InvocationMode $mode): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scan-replay-');
        self::assertIsString($path);
        $client = new RecordingScanRedis();
        $command = new ScanProbe();
        $command->setClientInvoker($mode->invoker());
        $config = new FuzzConfig();
        try {
            ScriptLogger::init($path, clients: [$client], invocationMode: $mode);
            $command->fuzz($client, $config);
            $command->fuzz($client, $config);
            $command->variant = 'referenced-alias';
            $command->fuzz($client, $config);
            ScriptLogger::finish();
            $expected = InvocationTrace::$calls;
            InvocationTrace::$calls = [];
            $reporting = error_reporting();
            try { require $path; } finally { error_reporting($reporting); }
            self::assertSame($expected, InvocationTrace::$calls);
        } finally {
            ScriptLogger::finish();
            unlink($path);
        }
    }
}
