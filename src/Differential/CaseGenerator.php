<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

/**
 * Generates differential testcases from a seed.
 *
 * A lightweight model of the case's keys (type and known members) steers most
 * operations at keys of the right type, so commands do real work, while the
 * configured rates deliberately aim a share of them at missing keys, keys of
 * the wrong type, and boundary arguments. The model only guides generation;
 * Redis remains the authority on state, so a key whose outcome the model
 * cannot predict is simply marked unknown.
 *
 * Setup, test, and option choices each draw from their own seeded stream, so
 * changing `steps` truncates the test sequence without changing anything
 * before the cut.
 *
 * @phpstan-import-type Operation from DiffRuntime
 * @phpstan-type KeyState array{type: string|null, members: list<string>, len: int}
 */
final class CaseGenerator
{
    public const TYPES = ['string', 'hash', 'list', 'set', 'zset'];

    /**
     * Operation name => [group, weight]. Names are client method names.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const CATALOG = [
        // strings
        'get' => ['string', 6], 'set' => ['string', 6], 'setnx' => ['string', 2], 'getset' => ['string', 1],
        'append' => ['string', 2], 'strlen' => ['string', 2], 'incr' => ['string', 2], 'incrBy' => ['string', 2],
        'incrByFloat' => ['string', 2], 'decr' => ['string', 1], 'decrBy' => ['string', 1],
        'getRange' => ['string', 2], 'setRange' => ['string', 2], 'mget' => ['string', 3], 'mset' => ['string', 2],
        'msetnx' => ['string', 1], 'getDel' => ['string', 1], 'getEx' => ['string', 1], 'setex' => ['string', 1],
        'psetex' => ['string', 1], 'lcs' => ['string', 1],
        // generic
        'del' => ['generic', 3], 'unlink' => ['generic', 1], 'exists' => ['generic', 2], 'type' => ['generic', 2],
        'expire' => ['generic', 2], 'pexpire' => ['generic', 1], 'expireAt' => ['generic', 1],
        'persist' => ['generic', 1], 'ttl' => ['generic', 2], 'pttl' => ['generic', 2], 'rename' => ['generic', 1],
        'renameNx' => ['generic', 1], 'copy' => ['generic', 1], 'touch' => ['generic', 1], 'object' => ['generic', 1],
        // hashes
        'hSet' => ['hash', 5], 'hSetNx' => ['hash', 1], 'hGet' => ['hash', 4], 'hMget' => ['hash', 2],
        'hMset' => ['hash', 2], 'hGetAll' => ['hash', 3], 'hDel' => ['hash', 2], 'hExists' => ['hash', 2],
        'hLen' => ['hash', 2], 'hKeys' => ['hash', 2], 'hVals' => ['hash', 2], 'hIncrBy' => ['hash', 2],
        'hIncrByFloat' => ['hash', 2], 'hStrLen' => ['hash', 1], 'hRandField' => ['hash', 1],
        // lists
        'lPush' => ['list', 3], 'rPush' => ['list', 3], 'lPushx' => ['list', 1], 'rPushx' => ['list', 1],
        'lPop' => ['list', 2], 'rPop' => ['list', 2], 'lRange' => ['list', 4], 'lIndex' => ['list', 2],
        'lSet' => ['list', 2], 'lLen' => ['list', 2], 'lInsert' => ['list', 1], 'lRem' => ['list', 1],
        'lTrim' => ['list', 1], 'lPos' => ['list', 1], 'lMove' => ['list', 1], 'rpoplpush' => ['list', 1],
        // sets
        'sAdd' => ['set', 4], 'sRem' => ['set', 2], 'sMembers' => ['set', 3], 'sIsMember' => ['set', 2],
        'sMisMember' => ['set', 1], 'sCard' => ['set', 2], 'sPop' => ['set', 1], 'sRandMember' => ['set', 1],
        'sMove' => ['set', 1], 'sInter' => ['set', 1], 'sUnion' => ['set', 1], 'sDiff' => ['set', 1],
        'sInterStore' => ['set', 1], 'sUnionStore' => ['set', 1], 'sDiffStore' => ['set', 1],
        'sintercard' => ['set', 1],
        // sorted sets
        'zAdd' => ['zset', 5], 'zIncrBy' => ['zset', 2], 'zScore' => ['zset', 3], 'zMscore' => ['zset', 1],
        'zRank' => ['zset', 2], 'zRevRank' => ['zset', 1], 'zCard' => ['zset', 2], 'zCount' => ['zset', 1],
        'zRange' => ['zset', 4], 'zRangeByScore' => ['zset', 2], 'zRevRange' => ['zset', 1],
        'zRevRangeByScore' => ['zset', 1], 'zRangeByLex' => ['zset', 1], 'zRem' => ['zset', 2],
        'zRemRangeByScore' => ['zset', 1], 'zRemRangeByRank' => ['zset', 1], 'zPopMin' => ['zset', 1],
        'zPopMax' => ['zset', 1], 'zunionstore' => ['zset', 1], 'zinterstore' => ['zset', 1],
        'zLexCount' => ['zset', 1], 'zRandMember' => ['zset', 1],
        // transaction and pipeline blocks (drawn at --txn-chance, not by weight)
        'multi' => ['txn', 0], 'pipeline' => ['txn', 0],
        // whole-database commands: only with allowFlush
        'flushdb' => ['flush', 1], 'flushall' => ['flush', 1],
    ];

    /** @var array<string, KeyState> */
    private array $model = [];

    private int $fresh = 0;

    private string $prefix = '';

    private Rng $rng;

    private ValueSource $values;

    private bool $serializing = false;

    public function __construct(private readonly GeneratorOptions $options)
    {
        $this->rng = new Rng(0);
        $this->values = new ValueSource($this->rng, 0.0, 1);
    }

    /** @return list<string> */
    public static function operationNames(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * Resolve a filter such as `@hash,get,-hGetAll` into operation names.
     * Positive entries are unioned (all non-flush operations when there are
     * none); entries prefixed with `-` or `!` are removed afterwards.
     *
     * @param list<string> $patterns
     * @return list<string>
     */
    public static function resolveFilter(array $patterns): array
    {
        $include = [];
        $exclude = [];
        foreach ($patterns as $pattern) {
            $negated = $pattern !== '' && ($pattern[0] === '-' || $pattern[0] === '!');
            $name = $negated ? substr($pattern, 1) : $pattern;
            $matched = [];
            foreach (self::CATALOG as $operation => [$group]) {
                if ($name === '@' . $group || strcasecmp($name, $operation) === 0 || ($name === '@all' && $group !== 'flush')) {
                    $matched[] = $operation;
                }
            }
            if ($matched === []) {
                throw new \InvalidArgumentException("unknown differential operation or group: {$name}");
            }
            if ($negated) {
                $exclude = [...$exclude, ...$matched];
            } else {
                $include = [...$include, ...$matched];
            }
        }

        if ($include === []) {
            foreach (self::CATALOG as $operation => [$group]) {
                if ($group !== 'flush') {
                    $include[] = $operation;
                }
            }
        }

        return array_values(array_unique(array_diff($include, $exclude)));
    }

    public function generate(int $caseSeed, int $index, string $prefix): TestCase
    {
        $this->prefix = $prefix;
        $this->model = [];
        $this->fresh = 0;

        $choices = Rng::derive($caseSeed, 'options');
        $options = [
            'serializer' => $choices->pick($this->nonEmpty($this->options->serializers)),
            'compression' => $choices->pick($this->nonEmpty($this->options->compressions)),
            'relay_cache' => $choices->pick($this->nonEmpty($this->options->relayCache)),
        ];
        $this->serializing = $options['serializer'] !== 'none';
        $order = match ($this->options->order) {
            'phpredis-first' => [DiffRuntime::PHPREDIS, DiffRuntime::RELAY],
            'relay-first' => [DiffRuntime::RELAY, DiffRuntime::PHPREDIS],
            default => $choices->chance(0.5)
                ? [DiffRuntime::PHPREDIS, DiffRuntime::RELAY]
                : [DiffRuntime::RELAY, DiffRuntime::PHPREDIS],
        };

        $this->use(Rng::derive($caseSeed, 'setup'));
        $setup = $this->setup();

        $this->use(Rng::derive($caseSeed, 'test'));
        $test = [];
        $weights = $this->weights();
        while (count($test) < $this->options->steps) {
            $test = [...$test, ...$this->next($weights)];
        }

        return new TestCase($caseSeed, $index, $prefix, $setup, array_slice($test, 0, $this->options->steps), $options, $order);
    }

    private function use(Rng $rng): void
    {
        $this->rng = $rng;
        $this->values = new ValueSource($rng, $this->options->edgeChance, $this->options->maxValueBytes);
    }

    /**
     * @template T
     * @param list<T> $items
     * @return non-empty-list<T>
     */
    private function nonEmpty(array $items): array
    {
        if ($items === []) {
            throw new \LogicException('empty choice list');
        }

        return $items;
    }

    /** @return array<string, int> */
    private function weights(): array
    {
        $enabled = $this->options->commands ?? self::resolveFilter([]);
        $weights = [];
        foreach ($enabled as $name) {
            [$group, $weight] = self::CATALOG[$name];
            if ($group === 'txn' || ($group === 'flush' && !$this->options->allowFlush)) {
                continue;
            }
            $weights[$name] = $weight;
        }

        return $weights;
    }

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

    /** @return list<list<string>> */
    private function setup(): array
    {
        $commands = [];
        for ($i = 0; $i < $this->options->keys; $i++) {
            $key = $this->prefix . 'k' . $i;
            if ($this->values->edge()) {
                $key .= $this->rng->pick([":\0", ' ', "\xff", '{tag}', ':' . str_repeat('n', 200), "\r\n"]);
            }
            $this->model[$key] = ['type' => null, 'members' => [], 'len' => 0];
            if ($this->rng->chance(0.15)) {
                continue;
            }

            $type = $this->rng->pick(self::TYPES);
            $count = $this->rng->int(1, max(1, $this->options->members));
            $members = [];
            switch ($type) {
                case 'string':
                    $commands[] = ['SET', $key, $this->rng->chance(0.3) ? (string) $this->values->int() : $this->values->string()];
                    break;
                case 'hash':
                    $argv = ['HSET', $key];
                    for ($j = 0; $j < $count; $j++) {
                        $members[] = $field = $this->values->string();
                        $argv[] = $field;
                        $argv[] = $this->rng->chance(0.3) ? (string) $this->values->int() : $this->values->string();
                    }
                    $commands[] = $argv;
                    break;
                case 'list':
                    $argv = ['RPUSH', $key];
                    for ($j = 0; $j < $count; $j++) {
                        $members[] = $argv[] = $this->values->string();
                    }
                    $commands[] = $argv;
                    break;
                case 'set':
                    $argv = ['SADD', $key];
                    for ($j = 0; $j < $count; $j++) {
                        $members[] = $argv[] = $this->values->string();
                    }
                    $commands[] = $argv;
                    break;
                default:
                    $argv = ['ZADD', $key];
                    for ($j = 0; $j < $count; $j++) {
                        $argv[] = $this->rng->pick(['0', '1', '1.5', '-2', '3.25', '10', '+inf', '-inf', '0.1']);
                        $members[] = $argv[] = $this->values->string();
                    }
                    $commands[] = $argv;
            }

            $this->model[$key] = ['type' => $type, 'members' => array_values(array_unique($members)), 'len' => $count];
            if ($this->rng->chance($this->options->ttlChance)) {
                $commands[] = ['PEXPIRE', $key, (string) $this->rng->int(1_000_000, 100_000_000)];
            }
        }

        return $commands;
    }

    // ------------------------------------------------------------------
    // Key and member selection
    // ------------------------------------------------------------------

    /** A key the model believes has $type, redirected at the configured wrong-type and missing rates. */
    private function key(string $type): string
    {
        if ($this->rng->chance($this->options->missingChance)) {
            return $this->missingKey();
        }
        if ($this->rng->chance($this->options->wrongTypeChance)) {
            $others = $this->keysWhere(static fn (?string $t): bool => $t !== null && $t !== $type);
            if ($others !== []) {
                return $this->rng->pick($others);
            }
        }

        $matching = $this->keysWhere(static fn (?string $t): bool => $t === $type);
        if ($matching !== [] && $this->rng->chance(0.85)) {
            return $this->rng->pick($matching);
        }

        // Creating writes land on missing keys; reads fall back to anything.
        $missing = $this->keysWhere(static fn (?string $t): bool => $t === null);
        if ($missing !== [] && $this->rng->chance(0.6)) {
            return $this->rng->pick($missing);
        }

        return $this->anyKey();
    }

    private function anyKey(): string
    {
        $keys = array_map('strval', array_keys($this->model));
        if ($keys === [] || $this->rng->chance(0.03)) {
            return $this->missingKey();
        }

        return $this->rng->pick($keys);
    }

    private function missingKey(): string
    {
        $missing = $this->keysWhere(static fn (?string $t): bool => $t === null);
        if ($missing !== [] && $this->rng->chance(0.7)) {
            return $this->rng->pick($missing);
        }

        $key = $this->prefix . 'm' . $this->fresh++;
        $this->model[$key] = ['type' => null, 'members' => [], 'len' => 0];

        return $key;
    }

    /**
     * @param callable(string|null): bool $predicate
     * @return list<string>
     */
    private function keysWhere(callable $predicate): array
    {
        $keys = [];
        foreach ($this->model as $key => $state) {
            if ($predicate($state['type'])) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /** @return list<string> Two to four distinct-or-not keys, mostly of $type. */
    private function keys(string $type, int $min = 1, int $max = 4): array
    {
        $keys = [];
        $count = $this->rng->int($min, $max);
        for ($i = 0; $i < $count; $i++) {
            $keys[] = $this->key($type);
        }

        return $keys;
    }

    private function member(string $key): string
    {
        $members = $this->model[$key]['members'] ?? [];
        if ($members !== [] && $this->rng->chance(0.7)) {
            return $this->rng->pick($members);
        }

        return $this->values->string();
    }

    private function length(string $key): int
    {
        return $this->model[$key]['len'] ?? 0;
    }

    // ------------------------------------------------------------------
    // Model updates (approximate by design)
    // ------------------------------------------------------------------

    /** @param list<string> $members */
    private function write(string $key, string $type, array $members = [], int $grow = 0): void
    {
        $state = $this->model[$key] ?? ['type' => null, 'members' => [], 'len' => 0];
        if ($state['type'] !== null && $state['type'] !== $type && $state['type'] !== 'unknown') {
            return; // a wrong-type write fails and changes nothing
        }
        $state['type'] = $type;
        $state['members'] = array_slice(array_values(array_unique([...$state['members'], ...$members])), -64);
        $state['len'] = max(0, $state['len'] + $grow);
        $this->model[$key] = $state;
    }

    private function overwrite(string $key, ?string $type): void
    {
        $this->model[$key] = ['type' => $type, 'members' => [], 'len' => $type === null ? 0 : 1];
    }

    private function unknown(string $key): void
    {
        $state = $this->model[$key] ?? ['type' => null, 'members' => [], 'len' => 0];
        $state['type'] = 'unknown';
        $this->model[$key] = $state;
    }

    private function delete(string $key): void
    {
        $this->overwrite($key, null);
    }

    // ------------------------------------------------------------------
    // Operations
    // ------------------------------------------------------------------

    /**
     * @param array<string, int> $weights
     * @return list<Operation>
     */
    private function next(array $weights): array
    {
        $enabled = $this->options->commands;
        $txn = array_values(array_filter(['multi', 'pipeline'], static fn (string $name): bool => $enabled === null || in_array($name, $enabled, true)));
        if ($txn !== [] && $this->rng->chance($this->options->txnChance)) {
            return $this->block($this->rng->pick($txn), $weights);
        }
        if ($weights === []) {
            throw new \InvalidArgumentException('the command filter leaves no operations outside transaction blocks');
        }

        return [$this->operation((string) $this->rng->weighted($weights))];
    }

    /**
     * MULTI or PIPELINE, one to four queued operations, then EXEC (or, for
     * MULTI, occasionally DISCARD). The EXEC comparison mode carries each
     * queued operation's own mode.
     *
     * @param array<string, int> $weights
     * @return list<Operation>
     */
    private function block(string $kind, array $weights): array
    {
        $flat = array_filter($weights, static fn (string $name): bool => self::CATALOG[$name][1] > 0, ARRAY_FILTER_USE_KEY);
        if ($flat === []) {
            $flat = ['get' => 1];
        }

        $ops = [[$kind, [], 'exact']];
        $modes = [];
        $count = $this->rng->int(1, 4);
        for ($i = 0; $i < $count; $i++) {
            $op = $this->operation((string) $this->rng->weighted($flat));
            $ops[] = $op;
            $modes[] = $op[2];
        }

        if ($kind === 'multi' && $this->rng->chance(0.15)) {
            $ops[] = ['discard', [], 'exact'];
        } else {
            $ops[] = ['exec', [], 'exec:' . implode(',', $modes)];
        }

        return $ops;
    }

    /** @return Operation */
    private function operation(string $name): array
    {
        $v = $this->values;
        $r = $this->rng;

        switch ($name) {
            // ---------------- strings ----------------
            case 'get':
            case 'strlen':
            case 'incr':
            case 'decr':
            case 'getDel':
                $key = $this->key('string');
                if ($name === 'getDel') {
                    $this->delete($key);
                } elseif ($name === 'incr' || $name === 'decr') {
                    $this->write($key, 'string');
                }
                return [$name, [$key], 'exact'];

            case 'set':
                $key = $this->key('string');
                $args = [$key, $v->php($this->serializing)];
                if ($r->chance(0.4)) {
                    $args[] = $this->setOptions();
                }
                if (isset($args[2]) && is_array($args[2]) && (in_array('NX', $args[2], true) || in_array('XX', $args[2], true))) {
                    $this->unknown($key);
                } else {
                    $this->overwrite($key, 'string');
                }
                return ['set', $args, 'exact'];

            case 'setnx':
            case 'getset':
            case 'append':
                $key = $this->key('string');
                $this->write($key, 'string');
                return [$name, [$key, $name === 'append' ? $v->string() : $v->php($this->serializing)], 'exact'];

            case 'incrBy':
            case 'decrBy':
                $key = $this->key('string');
                $this->write($key, 'string');
                return [$name, [$key, $v->int()], 'exact'];

            case 'incrByFloat':
                $key = $this->key('string');
                $this->write($key, 'string');
                return [$name, [$key, $v->float()], 'exact'];

            case 'getRange':
                $key = $this->key('string');
                return [$name, [$key, $v->index(16), $v->index(16)], 'exact'];

            case 'setRange':
                $key = $this->key('string');
                $this->write($key, 'string');
                return [$name, [$key, $v->edge() ? $r->pick([-1, 536870912, 0]) : $r->int(0, 24), $v->string()], 'exact'];

            case 'mget':
                return [$name, [$this->keys('string', 1, 5)], 'exact'];

            case 'mset':
            case 'msetnx':
                $pairs = [];
                foreach ($this->keys('string', 1, 4) as $key) {
                    $pairs[$key] = $v->php($this->serializing);
                    $name === 'mset' ? $this->overwrite($key, 'string') : $this->unknown($key);
                }
                return [$name, [$pairs], 'exact'];

            case 'getEx':
                $key = $this->key('string');
                $options = $r->pick([
                    [], ['EX' => $v->ttlSeconds()], ['PX' => $v->ttlSeconds() * 1000], ['EXAT' => $v->unixTime()],
                    ['PXAT' => $v->unixTime() * 1000], ['PERSIST'],
                ]);
                return [$name, [$key, $options], 'exact'];

            case 'setex':
            case 'psetex':
                $key = $this->key('string');
                $this->overwrite($key, 'string');
                $ttl = $v->ttlSeconds();
                return [$name, [$key, $name === 'psetex' && $ttl > 0 && $ttl < PHP_INT_MAX / 1000 ? $ttl * 1000 : $ttl, $v->php($this->serializing)], 'exact'];

            case 'lcs':
                $options = $r->pick([null, ['LEN' => true], ['IDX' => true], ['IDX' => true, 'MINMATCHLEN' => 1, 'WITHMATCHLEN' => true]]);
                return [$name, [$this->key('string'), $this->key('string'), $options], 'exact'];

            // ---------------- generic ----------------
            case 'del':
            case 'unlink':
                $keys = $this->keys('string', 1, 3);
                foreach ($keys as $key) {
                    $this->delete($key);
                }
                return [$name, $r->chance(0.5) ? [$keys] : $keys, 'exact'];

            case 'exists':
            case 'touch':
                $keys = [];
                foreach (range(1, $r->int(1, 3)) as $_) {
                    $keys[] = $this->anyKey();
                }
                return [$name, $r->chance(0.5) ? [$keys] : $keys, 'exact'];

            case 'type':
                return [$name, [$this->anyKey()], 'exact'];

            case 'object':
                return [$name, [$r->pick(['encoding', 'refcount']), $this->anyKey()], 'exact'];

            case 'expire':
            case 'pexpire':
                $key = $this->anyKey();
                $ttl = $v->ttlSeconds();
                if ($ttl <= 0) {
                    $this->delete($key);
                }
                $args = [$key, $name === 'pexpire' && $ttl > 0 && $ttl < PHP_INT_MAX / 1000 ? $ttl * 1000 : $ttl];
                if ($r->chance(0.3)) {
                    $args[] = $r->pick(['NX', 'XX', 'GT', 'LT', 'nx', 'BOGUS']);
                }
                return [$name, $args, 'exact'];

            case 'expireAt':
                $key = $this->anyKey();
                $time = $v->unixTime();
                if ($time < 2_000_000_000) {
                    $this->delete($key);
                }
                return [$name, [$key, $time], 'exact'];

            case 'persist':
                return [$name, [$this->anyKey()], 'exact'];

            case 'ttl':
            case 'pttl':
                return [$name, [$this->anyKey()], $name];

            case 'rename':
            case 'renameNx':
            case 'copy':
                $source = $this->anyKey();
                $target = $r->chance(0.2) ? $source : $this->anyKey();
                $this->unknown($target);
                if ($name !== 'copy') {
                    $this->unknown($source);
                }
                $args = [$source, $target];
                if ($name === 'copy' && $r->chance(0.5)) {
                    $args[] = ['REPLACE' => true];
                }
                return [$name, $args, 'exact'];

            // ---------------- hashes ----------------
            case 'hSet':
                $key = $this->key('hash');
                $fields = [];
                $args = [$key];
                foreach (range(1, $r->int(1, 3)) as $_) {
                    $fields[] = $field = $this->member($key);
                    $args[] = $field;
                    $args[] = $v->php($this->serializing);
                }
                $this->write($key, 'hash', $fields, count($fields));
                if ($r->chance(0.3)) {
                    $map = [];
                    for ($i = 1; $i + 1 < count($args); $i += 2) {
                        $map[$this->mapKey($args[$i])] = $args[$i + 1];
                    }
                    $args = [$key, $map];
                }
                return [$name, $args, 'exact'];

            case 'hSetNx':
                $key = $this->key('hash');
                $field = $this->member($key);
                $this->write($key, 'hash', [$field], 1);
                return [$name, [$key, $field, $v->php($this->serializing)], 'exact'];

            case 'hMset':
                $key = $this->key('hash');
                $map = [];
                foreach (range(1, $r->int(1, 3)) as $_) {
                    $map[$this->mapKey($this->member($key))] = $v->php($this->serializing);
                }
                $this->write($key, 'hash', array_map('strval', array_keys($map)), count($map));
                return [$name, [$key, $map], 'exact'];

            case 'hGet':
            case 'hExists':
            case 'hStrLen':
                $key = $this->key('hash');
                return [$name, [$key, $this->member($key)], 'exact'];

            case 'hMget':
                $key = $this->key('hash');
                $fields = [];
                foreach (range(1, $r->int(1, 4)) as $_) {
                    $fields[] = $this->member($key);
                }
                return [$name, [$key, $fields], 'exact'];

            case 'hGetAll':
                return [$name, [$this->key('hash')], 'map'];

            case 'hKeys':
            case 'hVals':
                return [$name, [$this->key('hash')], 'set'];

            case 'hLen':
                return [$name, [$this->key('hash')], 'exact'];

            case 'hDel':
                $key = $this->key('hash');
                $args = [$key];
                foreach (range(1, $r->int(1, 3)) as $_) {
                    $args[] = $this->member($key);
                }
                $this->unknown($key);
                $this->model[$key]['type'] = 'hash';
                return [$name, $args, 'exact'];

            case 'hIncrBy':
                $key = $this->key('hash');
                $field = $this->member($key);
                $this->write($key, 'hash', [$field], 1);
                return [$name, [$key, $field, $v->int()], 'exact'];

            case 'hIncrByFloat':
                $key = $this->key('hash');
                $field = $this->member($key);
                $this->write($key, 'hash', [$field], 1);
                return [$name, [$key, $field, $v->float()], 'exact'];

            case 'hRandField':
                $key = $this->key('hash');
                $options = $r->pick([null, ['COUNT' => $v->count()], ['COUNT' => $v->count(), 'WITHVALUES' => true]]);
                return [$name, $options === null ? [$key] : [$key, $options], 'shape'];

            // ---------------- lists ----------------
            case 'lPush':
            case 'rPush':
                $key = $this->key('list');
                $elements = [];
                foreach (range(1, $r->int(1, 4)) as $_) {
                    $elements[] = $v->php($this->serializing);
                }
                $this->write($key, 'list', array_values(array_filter($elements, is_string(...))), count($elements));
                return [$name, [$key, ...$elements], 'exact'];

            case 'lPushx':
            case 'rPushx':
                $key = $this->key('list');
                $value = $v->string();
                $this->write($key, 'list', [$value], 1);
                return [$name, [$key, $value], 'exact'];

            case 'lPop':
            case 'rPop':
                $key = $this->key('list');
                $this->unknown($key);
                return [$name, $r->chance(0.5) ? [$key] : [$key, $v->count()], 'exact'];

            case 'lRange':
                $key = $this->key('list');
                return [$name, [$key, $v->index($this->length($key)), $v->index($this->length($key))], 'exact'];

            case 'lIndex':
                $key = $this->key('list');
                return [$name, [$key, $v->index($this->length($key))], 'exact'];

            case 'lSet':
                $key = $this->key('list');
                return [$name, [$key, $v->index($this->length($key)), $v->php($this->serializing)], 'exact'];

            case 'lLen':
                return [$name, [$this->key('list')], 'exact'];

            case 'lInsert':
                $key = $this->key('list');
                $position = $r->pick(['BEFORE', 'AFTER', 'before', 'after', 'MIDDLE']);
                $this->write($key, 'list', [], 1);
                return [$name, [$key, $position, $this->member($key), $v->string()], 'exact'];

            case 'lRem':
                $key = $this->key('list');
                $this->unknown($key);
                return [$name, [$key, $this->member($key), $r->pick([0, 1, -1, 2, $v->count()])], 'exact'];

            case 'lTrim':
                $key = $this->key('list');
                $this->unknown($key);
                return [$name, [$key, $v->index($this->length($key)), $v->index($this->length($key))], 'exact'];

            case 'lPos':
                $key = $this->key('list');
                $options = $r->pick([null, ['RANK' => $r->pick([1, -1, 2, 0])], ['COUNT' => $r->pick([0, 1, 2])], ['MAXLEN' => $r->pick([0, 1, 5])], ['RANK' => -1, 'COUNT' => 0]]);
                return [$name, $options === null ? [$key, $this->member($key)] : [$key, $this->member($key), $options], 'exact'];

            case 'lMove':
            case 'rpoplpush':
                $source = $this->key('list');
                $target = $r->chance(0.3) ? $source : $this->key('list');
                $this->unknown($source);
                $this->unknown($target);
                return $name === 'lMove'
                    ? [$name, [$source, $target, $r->pick(['LEFT', 'RIGHT', 'left']), $r->pick(['LEFT', 'RIGHT', 'UP'])], 'exact']
                    : [$name, [$source, $target], 'exact'];

            // ---------------- sets ----------------
            case 'sAdd':
            case 'sRem':
                $key = $this->key('set');
                $members = [];
                foreach (range(1, $r->int(1, 4)) as $_) {
                    $members[] = $name === 'sAdd' && $r->chance(0.5) ? $v->php($this->serializing) : $this->member($key);
                }
                if ($name === 'sAdd') {
                    $this->write($key, 'set', array_values(array_filter($members, is_string(...))), count($members));
                } else {
                    $this->unknown($key);
                }
                return [$name, [$key, ...$members], 'exact'];

            case 'sMembers':
                return [$name, [$this->key('set')], 'set'];

            case 'sIsMember':
                $key = $this->key('set');
                return [$name, [$key, $this->member($key)], 'exact'];

            case 'sMisMember':
                $key = $this->key('set');
                $members = [];
                foreach (range(1, $r->int(1, 4)) as $_) {
                    $members[] = $this->member($key);
                }
                return [$name, [$key, ...$members], 'exact'];

            case 'sCard':
                return [$name, [$this->key('set')], 'exact'];

            case 'sPop':
                // Only whole-set pops: SPOP of fewer members than the set holds
                // is random, and would make the resulting state diverge.
                $key = $this->key('set');
                $this->delete($key);
                return [$name, [$key, 100_000], 'set'];

            case 'sRandMember':
                $key = $this->key('set');
                return [$name, $r->chance(0.3) ? [$key] : [$key, $v->count()], 'shape'];

            case 'sMove':
                $source = $this->key('set');
                $target = $this->key('set');
                $member = $this->member($source);
                $this->unknown($source);
                $this->write($target, 'set', [$member], 1);
                return [$name, [$source, $target, $member], 'exact'];

            case 'sInter':
            case 'sUnion':
            case 'sDiff':
                $keys = $this->keys('set', 1, 3);
                return [$name, $r->chance(0.5) ? [$keys] : $keys, 'set'];

            case 'sInterStore':
            case 'sUnionStore':
            case 'sDiffStore':
                $target = $this->key('set');
                $keys = $this->keys('set', 1, 3);
                $this->unknown($target);
                return [$name, [$target, ...$keys], 'exact'];

            case 'sintercard':
                $keys = $this->keys('set', 1, 3);
                return [$name, $r->chance(0.5) ? [$keys] : [$keys, $r->pick([0, 1, 2, -1])], 'exact'];

            // ---------------- sorted sets ----------------
            case 'zAdd':
                $key = $this->key('zset');
                $args = [$key];
                if ($r->chance(0.3)) {
                    $args[] = $r->pick([['NX'], ['XX'], ['CH'], ['INCR'], ['GT', 'CH'], ['LT'], ['NX', 'XX'], ['XX', 'CH']]);
                }
                $members = [];
                foreach (range(1, $r->int(1, 3)) as $_) {
                    $args[] = $v->score();
                    $members[] = $member = $this->member($key);
                    $args[] = $member;
                }
                $this->write($key, 'zset', $members, count($members));
                return [$name, $args, 'exact'];

            case 'zIncrBy':
                $key = $this->key('zset');
                $member = $this->member($key);
                $this->write($key, 'zset', [$member], 1);
                return [$name, [$key, $v->float(), $member], 'exact'];

            case 'zScore':
            case 'zRank':
            case 'zRevRank':
                $key = $this->key('zset');
                return [$name, [$key, $this->member($key)], 'exact'];

            case 'zMscore':
                $key = $this->key('zset');
                $members = [];
                foreach (range(1, $r->int(1, 3)) as $_) {
                    $members[] = $this->member($key);
                }
                return [$name, [$key, ...$members], 'exact'];

            case 'zCard':
                return [$name, [$this->key('zset')], 'exact'];

            case 'zCount':
            case 'zRemRangeByScore':
                $key = $this->key('zset');
                if ($name !== 'zCount') {
                    $this->unknown($key);
                }
                return [$name, [$key, $v->scoreBound(), $v->scoreBound()], 'exact'];

            case 'zRange':
                $key = $this->key('zset');
                $options = $r->pick([null, true, ['withscores' => true], ['REV' => true], ['BYSCORE' => true, 'withscores' => true], ['BYSCORE' => true, 'LIMIT' => [0, 2]]]);
                if (is_array($options) && isset($options['BYSCORE'])) {
                    return [$name, [$key, $v->scoreBound(), $v->scoreBound(), $options], 'exact'];
                }
                $length = $this->length($key);
                return [$name, $options === null ? [$key, $v->index($length), $v->index($length)] : [$key, $v->index($length), $v->index($length), $options], 'exact'];

            case 'zRangeByScore':
            case 'zRevRangeByScore':
                $key = $this->key('zset');
                $options = $r->pick([[], ['withscores' => true], ['limit' => [0, 2]], ['withscores' => true, 'limit' => [1, $v->count()]]]);
                return [$name, [$key, $v->scoreBound(), $v->scoreBound(), $options], 'exact'];

            case 'zRevRange':
                $key = $this->key('zset');
                $length = $this->length($key);
                return [$name, [$key, $v->index($length), $v->index($length), $r->chance(0.5)], 'exact'];

            case 'zRangeByLex':
            case 'zLexCount':
                $key = $this->key('zset');
                return [$name, [$key, $v->lexBound(), $v->lexBound()], 'exact'];

            case 'zRem':
                $key = $this->key('zset');
                $members = [];
                foreach (range(1, $r->int(1, 3)) as $_) {
                    $members[] = $this->member($key);
                }
                $this->unknown($key);
                return [$name, [$key, ...$members], 'exact'];

            case 'zRemRangeByRank':
                $key = $this->key('zset');
                $this->unknown($key);
                return [$name, [$key, $v->index($this->length($key)), $v->index($this->length($key))], 'exact'];

            case 'zPopMin':
            case 'zPopMax':
                $key = $this->key('zset');
                $this->unknown($key);
                return [$name, $r->chance(0.5) ? [$key] : [$key, $v->count()], 'exact'];

            case 'zunionstore':
            case 'zinterstore':
                $target = $this->key('zset');
                $keys = $this->keys('zset', 1, 3);
                $this->unknown($target);
                $args = [$target, $keys];
                if ($r->chance(0.4)) {
                    $args[] = array_map(fn (): float|int => $r->pick([1, 2, 0.5, -1, 0]), $keys);
                    if ($r->chance(0.5)) {
                        $args[] = $r->pick(['SUM', 'MIN', 'MAX', 'min', 'AVG']);
                    }
                }
                return [$name, $args, 'exact'];

            case 'zRandMember':
                $key = $this->key('zset');
                $options = $r->pick([null, ['COUNT' => $v->count()], ['COUNT' => $v->count(), 'WITHSCORES' => true]]);
                return [$name, $options === null ? [$key] : [$key, $options], 'shape'];

            // ---------------- whole database ----------------
            case 'flushdb':
            case 'flushall':
                foreach (array_keys($this->model) as $key) {
                    $this->delete((string) $key);
                }
                return [$name, [], 'exact'];
        }

        throw new \LogicException("no generator for {$name}");
    }

    /** @return list<mixed>|array<string, mixed>|int */
    private function setOptions(): array|int
    {
        $v = $this->values;

        return $this->rng->pick([
            ['NX'], ['XX'], ['EX' => $v->ttlSeconds()], ['PX' => $v->ttlSeconds() * 1000], ['KEEPTTL'], ['GET'],
            ['NX', 'EX' => $v->ttlSeconds()], ['XX', 'GET'], ['NX', 'GET'], ['EXAT' => $v->unixTime()],
            ['PXAT' => $v->unixTime() * 1000], $v->ttlSeconds(), ['EX' => $v->ttlSeconds(), 'PX' => 1000],
        ]);
    }

    /**
     * Hash fields used as PHP array keys. A numeric field such as "1" becomes
     * an integer array key either way; keeping it an integer here records what
     * the client actually receives.
     */
    private function mapKey(mixed $field): int|string
    {
        if (is_int($field)) {
            return $field;
        }

        return is_scalar($field) ? (string) $field : '';
    }
}
