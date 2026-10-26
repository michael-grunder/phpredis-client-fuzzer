<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class CollectionCacheScenario extends CacheScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return [...parent::requiredMethods($client), 'set', 'get', 'getrange', 'bitcount', 'bitpos',
            'hset', 'hget', 'hmget', 'hgetall', 'hkeys', 'hvals', 'hrandfield', 'hdel',
            'rpush', 'lrange', 'lindex', 'llen', 'lset', 'lpop',
            'sadd', 'smembers', 'sinter', 'sunion', 'sdiff', 'srandmember', 'srem',
            'zadd', 'zrange', 'zscore', 'zrank', 'zrem'];
    }

    protected function fixtures(ScenarioRun $run): void
    {
        $this->strings($run);
        $this->hashes($run);
        $this->lists($run);
        $this->sets($run);
        $this->sortedSets($run);
    }

    private function strings(ScenarioRun $run): void
    {
        $key = $run->key('string');
        $run->call('del', [$key]);
        foreach (['', "\x00\xff\x80A", str_repeat("\x00\xff", 2048)] as $index => $value) {
            $label = 'string-' . $index;
            $run->expect($label . ':set', true, $run->call('set', [$key, $value]));
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $this->check($run, $label . ':warm-' . $repeat, $value, 'get', [$key]);
                $this->check($run, $label . ':negative-range', substr($value, -2), 'getrange', [$key, -2, -1]);
                $this->check($run, $label . ':out-of-range', '', 'getrange', [$key, strlen($value) + 1, strlen($value) + 5]);
                $bits = 0;
                foreach (str_split($value) as $byte) {
                    $bits += substr_count(decbin(ord($byte)), '1');
                }
                $this->check($run, $label . ':bits', $bits, 'bitcount', [$key]);
                $this->check($run, $label . ':first-set-bit', $value === '' ? -1 : 8, 'bitpos', [$key, true]);
                $this->check($run, $label . ':byte-offset', $value === '' ? -1 : 8, 'bitpos', [$key, true, 1, 1]);
                $this->check($run, $label . ':bit-offset', $value === '' ? -1 : 8, 'bitpos', [$key, true, 8, 15, true]);
            }
        }
    }

    private function hashes(ScenarioRun $run): void
    {
        $key = $run->key('hash');
        $run->call('del', [$key]);
        $values = ['alpha' => $run->value('one'), 'beta' => $run->value('two'), 'gamma' => "\x00three"];
        foreach ($values as $field => $value) {
            $run->expect('hash:insert-' . $field, 1, $run->call('hset', [$key, $field, $value]));
        }
        $this->check($run, 'hash:partial', $values['alpha'], 'hget', [$key, 'alpha']);
        $this->check($run, 'hash:partial-missing', ['beta' => $values['beta'], 'absent' => false], 'hmget', [$key, ['beta', 'absent']]);
        for ($stage = 0; $stage < 2; $stage++) {
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $this->unordered($run, 'hash:complete', $values, $this->read($run, 'hgetall', [$key], 'hash:warm'), true);
                $this->unordered($run, 'hash:keys', array_keys($values), $this->read($run, 'hkeys', [$key], 'hash:keys'));
                $this->unordered($run, 'hash:values', array_values($values), $this->read($run, 'hvals', [$key], 'hash:values'));
                $this->check($run, 'hash:missing', false, 'hget', [$key, 'absent']);
                $this->sample($run, 'hash:random-one', array_keys($values), $this->read($run, 'hrandfield', [$key], 'hash:random-one'));
                $this->unordered($run, 'hash:random-all', $values,
                    $this->read($run, 'hrandfield', [$key, ['count' => 10, 'withvalues' => true]], 'hash:random-all'), true);
                $this->sample($run, 'hash:random-repeated', array_keys($values),
                    $this->read($run, 'hrandfield', [$key, ['count' => -5]], 'hash:random-repeated'), 5);
            }
            if ($stage === 0) {
                $values['alpha'] = $run->value('changed');
                unset($values['beta']);
                $run->expect('hash:update', 0, $run->call('hset', [$key, 'alpha', $values['alpha']]));
                $run->expect('hash:remove', 1, $run->call('hdel', [$key, 'beta']));
                $this->check($run, 'hash:invalidated-field', $values['alpha'], 'hget', [$key, 'alpha']);
                $this->check($run, 'hash:deleted-field', false, 'hget', [$key, 'beta']);
            }
        }
    }

    private function lists(ScenarioRun $run): void
    {
        $key = $run->key('list');
        $run->call('del', [$key]);
        $values = [$run->value('first'), '', "\x00last"];
        $run->expect('list:populate', 3, $run->call('rpush', [$key, ...$values]));
        $this->check($run, 'list:partial', [$values[0]], 'lrange', [$key, 0, 0]);
        for ($stage = 0; $stage < 2; $stage++) {
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $this->check($run, 'list:complete', $values, 'lrange', [$key, 0, -1]);
                $this->check($run, 'list:length', count($values), 'llen', [$key]);
                $this->check($run, 'list:last', $values[count($values) - 1], 'lindex', [$key, -1]);
                $this->check($run, 'list:negative-range', array_slice($values, -2), 'lrange', [$key, -2, -1]);
                $this->check($run, 'list:outside', false, 'lindex', [$key, count($values)]);
                $this->check($run, 'list:empty-range', [], 'lrange', [$key, 5, 8]);
            }
            if ($stage === 0) {
                $values[1] = 'changed';
                $run->expect('list:update', true, $run->call('lset', [$key, 1, 'changed']));
                $run->expect('list:pop', array_shift($values), $run->call('lpop', [$key]));
            }
        }
        $run->call('del', [$key]);
        $this->check($run, 'list:empty', [], 'lrange', [$key, 0, -1]);
        $this->check($run, 'list:empty-length', 0, 'llen', [$key]);
    }

    private function sets(ScenarioRun $run): void
    {
        $left = $run->key('set-left');
        $right = $run->key('set-right');
        foreach ([['b', 'd'], ['x'], ['a', 'b', 'c']] as $index => $other) {
            $run->call('del', [[$left, $right]]);
            $values = ['a', 'b', 'c'];
            $run->expect('set:populate-left', 3, $run->call('sadd', [$left, ...$values]));
            $run->expect('set:populate-right', count($other), $run->call('sadd', [$right, ...$other]));
            for ($stage = 0; $stage < 2; $stage++) {
                for ($repeat = 0; $repeat < 2; $repeat++) {
                    $this->unordered($run, 'set:left', $values, $this->read($run, 'smembers', [$left], 'set:warm-left'));
                    $this->unordered($run, 'set:right', $other, $this->read($run, 'smembers', [$right], 'set:warm-right'));
                    // Reverse unequal inputs as well: INTER and DIFF sort by size differently.
                    foreach ([[$left, $right, $values, $other], [$right, $left, $other, $values]] as [$a, $b, $av, $bv]) {
                        foreach (['sinter' => array_intersect($av, $bv), 'sunion' => array_unique([...$av, ...$bv]), 'sdiff' => array_diff($av, $bv)] as $method => $expected) {
                            $this->unordered($run, 'set-' . $index . ':' . $method, $expected, $this->read($run, $method, [$a, $b], 'set:' . $method));
                        }
                    }
                    $this->sample($run, 'set:random-one', $values, $this->read($run, 'srandmember', [$left], 'set:random-one'));
                    $this->unordered($run, 'set:random-all', $values, $this->read($run, 'srandmember', [$left, 10], 'set:random-all'));
                    $this->sample($run, 'set:random-repeated', $values, $this->read($run, 'srandmember', [$left, -5], 'set:random-repeated'), 5);
                }
                if ($stage === 0) {
                    $run->expect('set:remove', 1, $run->call('srem', [$left, 'b']));
                    $values = ['a', 'c'];
                }
            }
        }
    }

    private function sortedSets(ScenarioRun $run): void
    {
        $key = $run->key('zset');
        $lex = $run->key('zset-lex');
        $run->call('del', [[$key, $lex]]);
        $run->expect('zset:populate', 3, $run->call('zadd', [$key, 1, 'a', 1, 'b', 3, 'c']));
        $run->expect('lex:populate', 3, $run->call('zadd', [$lex, 0, 'a', 0, 'b', 0, 'c']));
        for ($stage = 0; $stage < 2; $stage++) {
            $ranked = $stage === 0 ? ['a', 'b', 'c'] : ['c', 'a'];
            $scores = $stage === 0 ? ['a' => 1.0, 'b' => 1.0, 'c' => 3.0] : ['c' => 0.0, 'a' => 1.0];
            $letters = $stage === 0 ? ['a', 'b', 'c'] : ['a', 'c'];
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $this->check($run, 'zset:rank-warm', $ranked, 'zrange', [$key, 0, -1]);
                $this->check($run, 'zset:scores', $scores, 'zrange', [$key, 0, -1, ['withscores' => true]]);
                $this->check($run, 'zset:reverse', array_reverse($scores, true), 'zrange', [$key, 0, -1, ['rev', 'withscores' => true]]);
                $this->check($run, 'zset:score-warm', $ranked, 'zrange', [$key, '-inf', '+inf', ['byscore']]);
                $this->check($run, 'zset:exclusive-score', $stage === 0 ? ['c'] : [], 'zrange', [$key, '(1', '+inf', ['byscore']]);
                $this->check($run, 'zset:inclusive-score', $stage === 0 ? ['a', 'b'] : ['a'], 'zrange', [$key, '1', '1', ['byscore']]);
                $this->check($run, 'zset:negative-rank', [$ranked[count($ranked) - 1]], 'zrange', [$key, -1, -1]);
                $this->check($run, 'zset:missing-score', false, 'zscore', [$key, 'missing']);
                $this->check($run, 'zset:missing-rank', false, 'zrank', [$key, 'missing']);
                $this->check($run, 'zset:limited-intentional-miss', [$ranked[0]], 'zrange', [$key, '-inf', '+inf', ['byscore', 'limit' => [0, 1]]]);
                // A rank snapshot does not substitute for a complete lex snapshot.
                $this->check($run, 'lex:rank-warm', $letters, 'zrange', [$lex, 0, -1]);
                $this->check($run, 'lex:lex-warm', $letters, 'zrange', [$lex, '-', '+', ['bylex']]);
                $this->check($run, 'lex:exclusive', $stage === 0 ? ['b', 'c'] : ['c'], 'zrange', [$lex, '(a', '[c', ['bylex']]);
                $this->check($run, 'lex:reverse', array_reverse($letters), 'zrange', [$lex, '+', '-', ['bylex', 'rev']]);
                $this->check($run, 'lex:limited-intentional-miss', ['a'], 'zrange', [$lex, '-', '+', ['bylex', 'limit' => [0, 1]]]);
            }
            if ($stage === 0) {
                $run->expect('zset:update-score', 0, $run->call('zadd', [$key, 0, 'c']));
                $run->expect('zset:remove', 1, $run->call('zrem', [$key, 'b']));
                $run->expect('lex:remove', 1, $run->call('zrem', [$lex, 'b']));
            }
        }
    }

    /** @param list<string> $members */
    private function sample(ScenarioRun $run, string $label, array $members, mixed $reply, ?int $count = null): void
    {
        $valid = $count === null ? is_string($reply) && in_array($reply, $members, true)
            : is_array($reply) && array_is_list($reply) && count($reply) === $count;
        if ($count !== null && is_array($reply)) {
            foreach ($reply as $member) {
                $valid = $valid && is_string($member) && in_array($member, $members, true);
            }
        }
        $run->expectThat($label, 'Requested number of samples, each belonging to the fixture', $valid);
    }
}
