<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulScenarioRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class CacheScenarioTest extends TestCase
{
    protected function tearDown(): void
    {
        Command::finishCapturedWarnings();
    }

    /** @return iterable<string, array{string, class-string<Redis|RedisCluster|Relay|Cluster>}> */
    public static function scenarios(): iterable
    {
        foreach (['cache-hll', 'cache-collections'] as $name) {
            foreach ([Redis::class, RedisCluster::class, Relay::class, Cluster::class] as $class) {
                yield $name . '-' . $class => [$name, $class];
            }
        }
    }

    /** @param class-string<Redis|RedisCluster|Relay|Cluster> $class */
    #[DataProvider('scenarios')]
    public function testOrderedFixturesAreRepeatableAndUseOneSlot(string $name, string $class): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Optional client extension is unavailable');
        }
        $client = $this->createStub($class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        $model = new CacheFixtureInvoker();
        $scenario = StatefulScenarioRegistry::create($name, $model);
        $first = $scenario->run($client, 20260914, 0);
        self::assertSame('passed', $first->status, $first->failure ?? '');
        $calls = $model->calls;
        $model->calls = [];
        $repeat = $scenario->run($client, 20260914, 0);
        self::assertSame('passed', $repeat->status, $repeat->failure ?? '');
        self::assertSame($calls, $model->calls);
        self::assertNotEmpty($model->keys);
        foreach (array_keys($model->keys) as $key) {
            self::assertStringContainsString(':{0}:', $key);
            self::assertStringContainsString('20260914:' . $name, $key);
        }
        self::assertNotContains('rawCommand', array_column($calls, 0));
        if ($name === 'cache-hll') {
            $methods = array_column($calls, 0);
            self::assertLessThan(array_search('pfcount', $methods, true), array_search('get', $methods, true));
            self::assertContains('pfmerge', $methods);
        } else {
            foreach (['getrange', 'bitpos', 'hmget', 'hgetall', 'lrange', 'sinter', 'sdiff', 'zrange'] as $method) {
                self::assertContains($method, array_column($calls, 0));
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function corruptions(): iterable
    {
        yield 'hll warmed count differs from server' => ['cache-hll', 'pfcount'];
        foreach (['getrange', 'hmget', 'lindex', 'sinter', 'zscore', 'hrandfield', 'srandmember'] as $method) {
            yield $method => ['cache-collections', $method];
        }
        yield 'mutation ignored' => ['cache-collections', 'lset'];
    }

    #[DataProvider('corruptions')]
    public function testIncorrectRepliesFailWithRecordedArguments(string $scenario, string $method): void
    {
        $client = $this->createStub(Redis::class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        $model = new CacheFixtureInvoker($method);
        $outcome = StatefulScenarioRegistry::create($scenario, $model)->run($client, 42, 0);
        self::assertSame('failed', $outcome->status);
        self::assertStringContainsString('Postcondition failed:', $outcome->failure ?? '');
        self::assertNotEmpty($outcome->steps);
        self::assertContains(false, array_column($outcome->postconditions, 'passed'));
        self::assertNotEmpty($outcome->steps[1]->argumentCode);
    }

    public function testNonBaselineOptionsSkipBeforeWritingAndDoNotChangeOptions(): void
    {
        $client = $this->createStub(Redis::class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        foreach (['cache-hll', 'cache-collections'] as $name) {
            $model = new CacheFixtureInvoker('getOption');
            $outcome = StatefulScenarioRegistry::create($name, $model)->run($client, 42, 0);
            self::assertSame('skipped', $outcome->status);
            self::assertSame([['getOption', [Redis::OPT_SERIALIZER]]], $model->calls);
        }
    }

    public function testCallerTransactionAndDefaultSelectionArePreserved(): void
    {
        self::assertSame([], StatefulScenarioRegistry::select([], 42));
        $client = $this->createStub(Redis::class);
        $client->method('getMode')->willReturn(Redis::MULTI);
        $model = new CacheFixtureInvoker();
        $outcome = StatefulScenarioRegistry::create('cache-collections', $model)->run($client, 42, 0);
        self::assertSame('failed', $outcome->status);
        self::assertSame([], $model->calls);
    }

    public function testRelayOracleRestoresCacheEvenWhenTheServerCountThrows(): void
    {
        if (!class_exists(Relay::class) || !defined(Relay::class . '::OPT_USE_CACHE')) {
            self::markTestSkipped('Optional Relay extension is unavailable');
        }
        foreach ([false, true] as $throws) {
            foreach ([false, true] as $enabled) {
                $client = $this->createStub(Relay::class);
                $client->method('getMode')->willReturn(Redis::ATOMIC);
                $model = new CacheFixtureInvoker(oracleThrows: $throws);
                $model->cacheEnabled = $enabled;
                $outcome = StatefulScenarioRegistry::create('cache-hll', $model)->run($client, 42, 0);
                self::assertSame($throws ? 'failed' : 'passed', $outcome->status, $outcome->failure ?? '');
                self::assertSame($enabled, $model->cacheEnabled);
                if ($throws) {
                    self::assertStringContainsString('oracle failed', $outcome->failure ?? '');
                } else {
                    self::assertNotEmpty(array_filter($outcome->postconditions,
                        static fn (array $p): bool => str_ends_with($p['name'], ':cache-counters')));
                }
            }
        }
    }
}

/** Small server-free value model, deliberately independent of any cache. */
final class CacheFixtureInvoker implements ClientInvoker
{
    /** @var array<string, array<string, string|float>> */
    private array $maps = [];
    /** @var array<string, list<string>> */
    private array $lists = [];
    /** @var array<string, string> */
    private array $strings = [];
    /** @var array<string, true> */
    public array $keys = [];
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];
    private bool $corrupted = false;
    public bool $cacheEnabled = true;

    public function __construct(private readonly ?string $corrupt = null, private readonly bool $oracleThrows = false)
    {
    }

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        $this->calls[] = [$method, $arguments];
        if ($method === $this->corrupt && !$this->corrupted) {
            $this->corrupted = true;
            return match ($method) {
                'getOption' => 1,
                'pfcount' => -1,
                'lset' => true, // Acknowledged but not applied.
                default => 'incorrect-reply',
            };
        }
        if ($method === 'getOption') {
            if (($client instanceof Relay || $client instanceof Cluster)
                && $arguments[0] === constant(Relay::class . '::OPT_USE_CACHE')) {
                return $this->cacheEnabled;
            }
            return 0;
        }
        if ($method === 'setOption') {
            TestCase::assertIsBool($arguments[1]);
            $this->cacheEnabled = $arguments[1];
            return true;
        }
        if ($method === 'stats') {
            return ['stats' => ['hits' => 0, 'misses' => 0, 'errors' => 0]];
        }
        if ($method === 'del') {
            $keys = is_array($arguments[0]) ? $arguments[0] : $arguments;
            foreach ($keys as $key) {
                TestCase::assertIsString($key);
                $this->keys[$key] = true;
                unset($this->maps[$key], $this->lists[$key], $this->strings[$key]);
            }
            return count($keys);
        }
        if ($method === 'pfcount') {
            if ($this->oracleThrows && !$this->cacheEnabled) {
                throw new \RuntimeException('oracle failed');
            }
            $keys = is_array($arguments[0]) ? $arguments[0] : [$arguments[0]];
            $values = [];
            foreach ($keys as $key) {
                TestCase::assertIsString($key);
                $values = [...$values, ...($this->lists[$key] ?? [])];
            }
            return count(array_unique($values));
        }
        $key = $arguments[0];
        TestCase::assertIsString($key);
        $this->keys[$key] = true;
        $tail = array_slice($arguments, 1);
        if (in_array($method, ['pfadd', 'pfmerge'], true)) {
            TestCase::assertIsArray($tail[0]);
            $values = $method === 'pfadd' ? ($this->lists[$key] ?? []) : [];
            foreach ($tail[0] as $value) {
                TestCase::assertIsString($value);
                $values = [...$values, ...($method === 'pfmerge' ? ($this->lists[$value] ?? []) : [$value])];
            }
            $this->lists[$key] = array_values(array_unique($values));
            $this->strings[$key] = 'HYLL' . chr(count($values) > 100 ? 0 : 1) . str_repeat("\0", 11);
            return $method === 'pfmerge' ? true : 1;
        }
        if ($method === 'set') {
            TestCase::assertIsString($tail[0]);
            $this->strings[$key] = $tail[0];
            return true;
        }
        $value = $this->strings[$key] ?? '';
        if ($method === 'get') {
            return $this->strings[$key] ?? false;
        }
        if ($method === 'getrange') {
            TestCase::assertIsInt($tail[0]);
            TestCase::assertIsInt($tail[1]);
            return $this->slice(str_split($value), $tail[0], $tail[1], join: true);
        }
        $bits = implode('', array_map(static fn (string $v): string => str_pad(decbin(ord($v)), 8, '0', STR_PAD_LEFT), str_split($value)));
        if ($method === 'bitcount') {
            return substr_count($bits, '1');
        }
        if ($method === 'bitpos') {
            $unit = ($tail[3] ?? false) === true ? 1 : 8;
            $start = $tail[1] ?? 0;
            TestCase::assertIsInt($start);
            $end = $tail[2] ?? -1;
            TestCase::assertIsInt($end);
            $range = substr($bits, $start * $unit, $end === -1 ? null : ($end - $start + 1) * $unit);
            $pos = strpos($range, $tail[0] === true ? '1' : '0');
            return $pos === false ? -1 : $pos + $start * $unit;
        }
        if (in_array($method, ['hset', 'hget', 'hdel', 'zscore', 'zrank', 'zrem'], true)) {
            TestCase::assertIsString($tail[0]);
            $field = $tail[0];
            $previous = $this->maps[$key][$field] ?? false;
            if ($method === 'hset') {
                TestCase::assertIsString($tail[1]);
                $this->maps[$key][$field] = $tail[1];
                return $previous === false ? 1 : 0;
            }
            if ($method === 'hdel' || $method === 'zrem') {
                unset($this->maps[$key][$field]);
                return $previous === false ? 0 : 1;
            }
            return $previous;
        }
        $map = $this->maps[$key] ?? [];
        if ($method === 'hmget') {
            TestCase::assertIsArray($tail[0]);
            $result = [];
            foreach ($tail[0] as $field) {
                TestCase::assertIsString($field);
                $result[$field] = $map[$field] ?? false;
            }
            return $result;
        }
        if ($method === 'hrandfield') {
            if ($tail === []) {
                return array_key_first($map);
            }
            $options = $tail[0];
            TestCase::assertIsArray($options);
            return isset($options['withvalues']) ? $map : array_fill(0, 5, array_key_first($map));
        }
        if (in_array($method, ['hgetall', 'hkeys', 'hvals'], true)) {
            return match ($method) {
                'hgetall' => array_reverse($map, true),
                'hkeys' => array_keys($map),
                default => array_values($map),
            };
        }
        if ($method === 'zadd') {
            $added = 0;
            for ($i = 0; $i < count($tail); $i += 2) {
                $member = $tail[$i + 1];
                TestCase::assertIsString($member);
                TestCase::assertIsInt($tail[$i]);
                $added += isset($this->maps[$key][$member]) ? 0 : 1;
                $this->maps[$key][$member] = (float) $tail[$i];
            }
            return $added;
        }
        if ($method === 'zrange') {
            return $this->zrange($map, $tail);
        }
        $list = $this->lists[$key] ?? [];
        if ($method === 'rpush' || $method === 'sadd') {
            foreach ($tail as $member) {
                TestCase::assertIsString($member);
                $list[] = $member;
            }
            $this->lists[$key] = $method === 'sadd' ? array_values(array_unique($list)) : $list;
            return count($tail);
        }
        if ($method === 'lset') {
            TestCase::assertIsInt($tail[0]);
            TestCase::assertIsString($tail[1]);
            $list[$tail[0]] = $tail[1];
            $this->lists[$key] = array_values($list);
            return true;
        }
        if ($method === 'lpop') {
            $reply = array_shift($list);
            $this->lists[$key] = $list;
            return $reply;
        }
        if ($method === 'srem') {
            $this->lists[$key] = array_values(array_filter($list, static fn (string $v): bool => $v !== $tail[0]));
            return count($list) - count($this->lists[$key]);
        }
        if ($method === 'lrange') {
            TestCase::assertIsInt($tail[0]);
            TestCase::assertIsInt($tail[1]);
            return $this->slice($list, $tail[0], $tail[1]);
        }
        if ($method === 'lindex') {
            TestCase::assertIsInt($tail[0]);
            return $list[$tail[0] < 0 ? count($list) + $tail[0] : $tail[0]] ?? false;
        }
        if ($method === 'llen') {
            return count($list);
        }
        if ($method === 'smembers' || $method === 'srandmember') {
            return $method === 'smembers' ? array_reverse($list)
                : ($tail === [] ? $list[0] : ($tail[0] === -5 ? array_fill(0, 5, $list[0]) : $list));
        }
        TestCase::assertIsString($tail[0]);
        $other = $this->lists[$tail[0]] ?? [];
        return match ($method) {
            'sinter' => array_values(array_intersect($list, $other)),
            'sdiff' => array_values(array_diff($list, $other)),
            'sunion' => array_values(array_unique([...$list, ...$other])),
            default => throw new \LogicException('Unmodeled method: ' . $method),
        };
    }

    /** @param list<string> $values
     * @return list<string>|string
     */
    private function slice(array $values, int $start, int $end, bool $join = false): array|string
    {
        $start = $start < 0 ? max(0, count($values) + $start) : $start;
        $end = $end < 0 ? count($values) + $end : $end;
        $result = array_slice($values, $start, max(0, $end - $start + 1));
        return $join ? implode('', $result) : $result;
    }

    /** @param array<string, string|float> $map
     * @param list<mixed> $arguments
     * @return array<string, string|float>|list<string>
     */
    private function zrange(array $map, array $arguments): array
    {
        $options = $arguments[2] ?? [];
        TestCase::assertIsArray($options);
        $lex = in_array('bylex', $options, true);
        $score = in_array('byscore', $options, true);
        $reverse = in_array('rev', $options, true);
        uksort($map, static fn (string $a, string $b): int => $lex ? strcmp($a, $b) : ($map[$a] <=> $map[$b] ?: strcmp($a, $b)));
        if ($reverse) {
            $map = array_reverse($map, true);
        }
        if ($score || $lex) {
            $min = $arguments[$reverse ? 1 : 0];
            $max = $arguments[$reverse ? 0 : 1];
            TestCase::assertIsString($min);
            TestCase::assertIsString($max);
            foreach ($map as $member => $value) {
                $v = $lex ? $member : $value;
                $low = $lex ? substr($min, 1) : (float) ltrim($min, '(');
                $high = $lex ? substr($max, 1) : (float) ltrim($max, '(');
                if ((!in_array($min, ['-', '-inf'], true) && (str_starts_with($min, '(') ? $v <= $low : $v < $low))
                    || (!in_array($max, ['+', '+inf'], true) && (str_starts_with($max, '(') ? $v >= $high : $v > $high))) {
                    unset($map[$member]);
                }
            }
            if (isset($options['limit'])) {
                TestCase::assertIsArray($options['limit']);
                TestCase::assertIsInt($options['limit'][0]);
                TestCase::assertIsInt($options['limit'][1]);
                $map = array_slice($map, $options['limit'][0], $options['limit'][1], true);
            }
        } else {
            TestCase::assertIsInt($arguments[0]);
            TestCase::assertIsInt($arguments[1]);
            $members = $this->slice(array_keys($map), $arguments[0], $arguments[1]);
            TestCase::assertIsArray($members);
            $map = array_intersect_key($map, array_flip($members));
        }
        return isset($options['withscores']) ? $map : array_keys($map);
    }
}
