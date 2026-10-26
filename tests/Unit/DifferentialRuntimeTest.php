<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Differential\DiffRuntime;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Outcome from DiffRuntime
 * @phpstan-import-type Execution from DiffRuntime
 */
final class DifferentialRuntimeTest extends TestCase
{
    public function testCanonicalFloatsCompareNanInfAndSignedZero(): void
    {
        self::assertSame(DiffRuntime::canonical(NAN), DiffRuntime::canonical(NAN));
        self::assertSame(['float' => 'INF'], DiffRuntime::canonical(INF));
        self::assertSame(['float' => '-INF'], DiffRuntime::canonical(-INF));
        self::assertNotSame(DiffRuntime::canonical(0.0), DiffRuntime::canonical(-0.0));
        self::assertNotSame(DiffRuntime::canonical(1.0), DiffRuntime::canonical(1));
        self::assertNotSame(DiffRuntime::canonical('1'), DiffRuntime::canonical(1));
    }

    public function testExactModeKeepsOrderingVisible(): void
    {
        self::assertNotSame(DiffRuntime::canonical(['a', 'b']), DiffRuntime::canonical(['b', 'a']));
        self::assertNotSame(DiffRuntime::canonical(['x' => 1, 'y' => 2]), DiffRuntime::canonical(['y' => 2, 'x' => 1]));
    }

    public function testSetAndMapModesOnlyForgiveOrdering(): void
    {
        self::assertSame(DiffRuntime::canonical(['a', 'b', 'a'], 'set'), DiffRuntime::canonical(['a', 'a', 'b'], 'set'));
        self::assertNotSame(DiffRuntime::canonical(['a', 'b'], 'set'), DiffRuntime::canonical(['a', 'b', 'b'], 'set'));
        self::assertSame(DiffRuntime::canonical(['x' => '1', 'y' => '2'], 'map'), DiffRuntime::canonical(['y' => '2', 'x' => '1'], 'map'));
        self::assertNotSame(DiffRuntime::canonical(['x' => '1'], 'map'), DiffRuntime::canonical(['x' => 1], 'map'));
        self::assertNotSame(DiffRuntime::canonical(false, 'set'), DiffRuntime::canonical([], 'set'));
    }

    public function testShapeModeComparesCountsButNotRandomMembers(): void
    {
        self::assertSame(DiffRuntime::canonical(['a', 'b'], 'shape'), DiffRuntime::canonical(['c', 'd'], 'shape'));
        self::assertNotSame(DiffRuntime::canonical(['a'], 'shape'), DiffRuntime::canonical(['a', 'b'], 'shape'));
        self::assertSame(DiffRuntime::canonical('a', 'shape'), DiffRuntime::canonical(7, 'shape'));
        self::assertNotSame(DiffRuntime::canonical(false, 'shape'), DiffRuntime::canonical(null, 'shape'));
        self::assertNotSame(DiffRuntime::canonical('a', 'shape'), DiffRuntime::canonical(false, 'shape'));
    }

    public function testExecModeAppliesEachQueuedOperationsMode(): void
    {
        self::assertSame(
            DiffRuntime::canonical([['b', 'a'], ['x']], 'exec:set,exact'),
            DiffRuntime::canonical([['a', 'b'], ['x']], 'exec:set,exact'),
        );
        self::assertNotSame(
            DiffRuntime::canonical([['b', 'a'], ['x']], 'exec:exact,exact'),
            DiffRuntime::canonical([['a', 'b'], ['x']], 'exec:exact,exact'),
        );
        // A reply that does not line up with the queued modes compares exactly.
        self::assertSame([false], DiffRuntime::canonical([false], 'exec:set,set'));
    }

    public function testQueuedClientObjectsCanonicalizeToOnePlaceholder(): void
    {
        self::assertSame('<client>', DiffRuntime::canonical(new \Redis()));
        self::assertSame('<object stdClass>', DiffRuntime::canonical(new \stdClass()));
    }

    public function testDiagnosticsCompareBySemanticsUnlessStrict(): void
    {
        $a = self::outcome(false, null, ['Client::zAdd(): scores must be numeric']);
        $b = self::outcome(false, null, ['Client::zadd(): Scores must be numeric, or +inf']);
        self::assertNull(DiffRuntime::outcomeDiff($a, $b, 'exact', 0));
        self::assertSame('PHP warnings differ', DiffRuntime::outcomeDiff($a, $b, 'exact', 0, true));

        $none = self::outcome(false);
        self::assertSame('PHP warnings differ', DiffRuntime::outcomeDiff($a, $none, 'exact', 0));

        $typeA = self::outcome(null, 'TypeError: one wording');
        $typeB = self::outcome(null, 'TypeError: another wording');
        $client = self::outcome(null, 'ClientException: one wording');
        self::assertNull(DiffRuntime::outcomeDiff($typeA, $typeB, 'exact', 0));
        self::assertSame('exception differs', DiffRuntime::outcomeDiff($typeA, $client, 'exact', 0));
        self::assertSame('exception differs', DiffRuntime::outcomeDiff($typeA, $typeB, 'exact', 0, true));

        // Server error text always compares exactly.
        $wrongType = self::outcome(false, null, [], 'WRONGTYPE Operation against a key holding the wrong kind of value');
        self::assertSame('getLastError() differs', DiffRuntime::outcomeDiff($wrongType, $none, 'exact', 0));
    }

    public function testTtlModesTolerateTimingButNotStateClasses(): void
    {
        self::assertNull(DiffRuntime::outcomeDiff(self::outcome(1000), self::outcome(998), 'ttl', 2000));
        self::assertSame('reply differs', DiffRuntime::outcomeDiff(self::outcome(1000), self::outcome(990), 'ttl', 2000));
        self::assertNull(DiffRuntime::outcomeDiff(self::outcome(100_000), self::outcome(98_500), 'pttl', 2000));
        self::assertSame('reply differs', DiffRuntime::outcomeDiff(self::outcome(-1), self::outcome(-2), 'pttl', 2000));
        self::assertSame('reply differs', DiffRuntime::outcomeDiff(self::outcome(5), self::outcome(-1), 'ttl', 2000));
        self::assertSame('reply differs', DiffRuntime::outcomeDiff(self::outcome(1000), self::outcome(999), 'exact', 2000));
    }

    public function testStateDiffFindsContentTypeExistenceAndTtlDifferences(): void
    {
        $base = [['p:a', 'string', 'x', -1], ['p:b', 'list', ['1', '2'], 50_000]];

        self::assertNull(DiffRuntime::stateDiff($base, [['p:a', 'string', 'x', -1], ['p:b', 'list', ['1', '2'], 49_000]], 2000));
        self::assertStringContainsString('contents differ', (string) DiffRuntime::stateDiff($base, [['p:a', 'string', 'y', -1], $base[1]], 2000));
        self::assertStringContainsString('type', (string) DiffRuntime::stateDiff($base, [['p:a', 'hash', 'x', -1], $base[1]], 2000));
        self::assertStringContainsString('exists only', (string) DiffRuntime::stateDiff($base, [$base[0]], 2000));
        self::assertStringContainsString('PTTL', (string) DiffRuntime::stateDiff($base, [$base[0], ['p:b', 'list', ['1', '2'], -1]], 2000));
        self::assertStringContainsString('PTTL', (string) DiffRuntime::stateDiff($base, [$base[0], ['p:b', 'list', ['1', '2'], 10_000]], 2000));
    }

    public function testCompareReportsFirstReplyAndFirstStateDivergence(): void
    {
        $case = [
            'prefix' => 'p:',
            'setup' => [],
            'test' => [['get', ['p:a'], 'exact'], ['set', ['p:a', 'v'], 'exact'], ['get', ['p:a'], 'exact']],
            'options' => ['serializer' => 'none', 'compression' => 'none', 'relay_cache' => true],
            'order' => ['phpredis', 'relay'],
        ];
        $same = self::outcome(true);
        $a = self::execution([$same, $same, self::outcome('v')], [[], [['p:a', 'string', 'v', -1]], [['p:a', 'string', 'v', -1]]]);
        $b = self::execution([$same, $same, self::outcome('w')], [[], [['p:a', 'string', 'w', -1]], [['p:a', 'string', 'w', -1]]]);

        $comparison = DiffRuntime::compare($case, $a, $b, 2000);

        self::assertTrue($comparison['diverged']);
        self::assertNull($comparison['initial']);
        self::assertSame(2, $comparison['reply']['index'] ?? null);
        self::assertSame(1, $comparison['state']['index'] ?? null);
        self::assertFalse(DiffRuntime::compare($case, $a, $a, 2000)['diverged']);
    }

    public function testNormalizeMessageOnlyRenamesTheClientClass(): void
    {
        self::assertSame('Client::get(): x', DiffRuntime::normalizeMessage('Redis::get(): x'));
        self::assertSame('Client::get(): x', DiffRuntime::normalizeMessage('Relay\\Relay::get(): x'));
        self::assertSame('RedisCluster::get(): x', DiffRuntime::normalizeMessage('RedisCluster::get(): x'));
        self::assertSame('MyRedis::get(): x', DiffRuntime::normalizeMessage('MyRedis::get(): x'));
    }

    public function testShowEscapesBinaryData(): void
    {
        self::assertSame('"a\\x00b\\xff"', DiffRuntime::show("a\0b\xff"));
        self::assertSame('["k" => 1.5, "l" => NAN]', DiffRuntime::show(['k' => 1.5, 'l' => ['float' => 'NAN']]));
        self::assertSame('"\\$x"', DiffRuntime::show('$x'));
    }

    public function testPipelineSpeaksRespAndKeepsTheConnectionInSyncAfterErrors(): void
    {
        [$client, $server] = self::socketPair();
        fwrite($server, "+OK\r\n-ERR boom\r\n\$3\r\na\0b\r\n*2\r\n:5\r\n\$-1\r\n");

        try {
            DiffRuntime::pipeline($client, [['SET', 'k', 'v'], ['BAD'], ['GET', 'k'], ['X']]);
            self::fail('an error reply must throw');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ERR boom (command #1: "BAD")', $exception->getMessage());
        }

        // Every reply was consumed, so the connection is still usable.
        fwrite($server, "\$1\r\nz\r\n");
        self::assertSame('z', DiffRuntime::command($client, ['GET', 'k']));

        $sent = (string) fread($server, 65536);
        self::assertStringStartsWith("*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$1\r\nv\r\n*1\r\n\$3\r\nBAD\r\n", $sent);
    }

    public function testSnapshotReadsOnlyThePrefixAndCanonicalizesUnorderedTypes(): void
    {
        [$client, $server] = self::socketPair();
        fwrite($server, implode('', [
            // SCAN
            "*2\r\n\$1\r\n0\r\n*3\r\n\$4\r\np:s1\r\n\$4\r\np:h1\r\n\$4\r\np:z1\r\n",
            // TYPE/PTTL for p:h1, p:s1, p:z1 (sorted)
            "+hash\r\n:-1\r\n+set\r\n:5000\r\n+zset\r\n:-1\r\n",
            // HGETALL, SMEMBERS, ZRANGE WITHSCORES
            "*4\r\n\$1\r\nb\r\n\$1\r\n2\r\n\$1\r\na\r\n\$1\r\n1\r\n",
            "*2\r\n\$1\r\ny\r\n\$1\r\nx\r\n",
            "*2\r\n\$1\r\nm\r\n\$3\r\n1.5\r\n",
        ]));

        $snapshot = DiffRuntime::snapshot($client, 'p:');

        self::assertSame([
            ['p:h1', 'hash', [['a', '1'], ['b', '2']], -1],
            ['p:s1', 'set', ['x', 'y'], 5000],
            ['p:z1', 'zset', ['m', '1.5'], -1],
        ], $snapshot);
        $sent = (string) fread($server, 65536);
        self::assertStringContainsString("\$5\r\nMATCH\r\n\$3\r\np:*\r\n", $sent);
    }

    public function testResetEscapesGlobCharactersAndRefusesAnEmptyPrefix(): void
    {
        [$client, $server] = self::socketPair();
        fwrite($server, "*2\r\n\$1\r\n0\r\n*0\r\n");
        self::assertSame(0, DiffRuntime::reset($client, 'p*[x]?:'));
        self::assertStringContainsString('p\\*\\[x\\]\\?:*', (string) fread($server, 65536));

        $this->expectException(\InvalidArgumentException::class);
        DiffRuntime::reset($client, '');
    }

    /** @return array{0: resource, 1: resource} */
    private static function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is unavailable');
        }
        stream_set_timeout($pair[0], 2);
        stream_set_blocking($pair[1], false);

        return [$pair[0], $pair[1]];
    }

    /**
     * @param list<string> $warnings
     * @return Outcome
     */
    private static function outcome(mixed $value, ?string $exception = null, array $warnings = [], ?string $error = null): array
    {
        return ['value' => DiffRuntime::canonical($value), 'exception' => $exception, 'warnings' => $warnings, 'error' => $error];
    }

    /**
     * @param list<Outcome> $outcomes
     * @param list<list<array{0: string, 1: string, 2: mixed, 3: int}>> $states
     * @return Execution
     */
    private static function execution(array $outcomes, array $states): array
    {
        return ['client' => 'phpredis', 'initial' => [], 'outcomes' => $outcomes, 'states' => $states, 'final' => $states[count($states) - 1]];
    }
}
