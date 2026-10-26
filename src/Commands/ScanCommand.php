<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Commands;

use Mgrunder\RedisClientTester\ScriptLogger;
use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class ScanCommand extends Command implements FuzzInterface, FuzzRawInterface
{
    /** @var \WeakMap<Redis|RedisCluster|Relay|Cluster, ScanState>|null */
    private ?\WeakMap $methodStates = null;
    /** @var \WeakMap<Redis|RedisCluster|Relay|Cluster, ScanState>|null */
    private ?\WeakMap $rawStates = null;

    // Scratch fields used only while the existing subclasses build a new scan.
    protected ?string $key = null;
    protected ?string $type = null;
    protected ?string $pattern = null;

    abstract protected function initArgs(FuzzConfig $config): void;

    public function flags(): int
    {
        return self::READ | self::SCAN;
    }

    /** Ordinary continuation dominates; edge probes never replace its state. */
    protected function chooseVariant(): string
    {
        if (rand(0, 7) !== 0) {
            return 'iterate';
        }
        $variants = [...ScanCursor::VARIANTS, 'restart'];
        return $variants[array_rand($variants)];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed
    {
        return $this->scan($client, $config, false);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed
    {
        return $this->scan($client, $config, true);
    }

    private function scan(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config, bool $raw): mixed
    {
        $this->methodStates ??= new \WeakMap();
        $this->rawStates ??= new \WeakMap();
        $states = $raw ? $this->rawStates : $this->methodStates;
        $modeArgs = self::isRelay($client) ? [true] : [];
        try {
            $mode = $this->invokeClient($client, 'getMode', $modeArgs);
        } catch (\Throwable $throwable) {
            unset($this->methodStates[$client], $this->rawStates[$client]);
            throw $throwable;
        }
        if ($mode !== Redis::ATOMIC) {
            unset($this->methodStates[$client], $this->rawStates[$client]);
            return false;
        }

        $context = $this->context($client, $config);
        $state = $states[$client] ?? null;
        if ($context === null || $state?->config !== $config || $state->context !== $context) {
            unset($states[$client]);
            $state = null;
        }
        // Always start a client's first ordinary iteration with NULL (method)
        // or zero (protocol). Explicit probes follow once it has been sampled.
        $variant = $state === null ? 'iterate' : $this->chooseVariant();
        $ordinary = $variant === 'iterate' || $variant === 'restart';
        if (!$ordinary || $variant === 'restart' || $state === null || ScanCursor::finished($state->cursor)) {
            $state = $this->newState($client, $config, $context ?? [], $raw);
        }
        if ($ordinary) {
            // An exception, rejection, malformed reply or changed arguments must
            // not leave an uncertain cursor available to the next step.
            unset($states[$client]);
        } else {
            $state->cursor = ScanCursor::value($variant);
        }
        ScriptLogger::comment($this->name() . ' variant=' . $variant . ' surface=' . ($raw ? 'raw' : 'method'));

        $args = $this->arguments($client, $config, $state, $raw);
        $cursorIndex = $raw ? null : ($state->key === null ? 0 : 1);
        $before = $args;
        if ($cursorIndex !== null) {
            unset($before[$cursorIndex]);
        }
        if ($variant === 'referenced-alias' && !$raw) {
            // Both caller variables refer to the actual output parameter.
            $cursor = $state->cursor;
            $alias =& $cursor;
            $args[$cursorIndex] =& $alias;
        }
        $result = $this->cmdArguments($client, $raw ? 'rawCommand' : $this->name(), $args, $cursorIndex, $redisError);
        if ($raw) {
            $cursor = is_array($result) && array_keys($result) === [0, 1] && is_array($result[1])
                ? $result[0] : null;
        } else {
            $cursor = $args[$cursorIndex] ?? null;
            unset($args[$cursorIndex]);
        }
        // Do not attach a hook's replacement key/route/pattern to the original
        // iteration. A changed prefix/database during a hook also invalidates it.
        if ($ordinary && !$redisError && $args === $before && $context !== null
            && $context === $this->context($client, $config)
            && ScanCursor::valid($cursor)
            && (is_int($cursor) || is_string($cursor) || $cursor === null)) {
            $state->cursor = $cursor;
            $states[$client] = $state;
        }
        return $result;
    }

    /** @param list<mixed> $context */
    private function newState(
        Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config, array $context, bool $raw,
    ): ScanState {
        $this->key = $this->pattern = $this->type = null;
        $this->initArgs($config);
        $route = $this->isCluster($client)
            ? ($raw ? $this->clusterRawRoutingKey() : $config->getRandomKey(self::ANY)) : null;
        return new ScanState($config, $context, $this->key, $this->pattern, $this->type, $route, $raw ? 0 : null);
    }

    /** @return list<mixed> */
    private function arguments(
        Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config, ScanState $state, bool $raw,
    ): array {
        $count = rand(0, $config->getCmdMaxKeys());
        if ($raw) {
            $args = $state->route === null ? [$this->name()] : [$state->route, $this->name()];
            if ($state->key !== null) {
                $args[] = $state->key;
            }
            // NULL and reference aliases have no wire representation.
            $args[] = $state->cursor ?? 0;
            if ($state->pattern !== null) {
                array_push($args, 'MATCH', $state->pattern);
            }
            if ($count > 0) {
                array_push($args, 'COUNT', $count);
            }
            if ($state->type !== null) {
                array_push($args, 'TYPE', $state->type);
            }
            return $args;
        }
        if ($state->key !== null) {
            return [$state->key, $state->cursor, $state->pattern, $count];
        }
        if (!$this->isCluster($client)) {
            return [$state->cursor, $state->pattern, $count, $state->type];
        }
        $args = [$state->cursor, $state->route, $state->pattern, $count];
        // PhpRedis RedisCluster::scan has no TYPE argument; Relay\Cluster does.
        if ((new \ReflectionMethod($client, 'scan'))->getNumberOfParameters() >= 5) {
            $args[] = $state->type;
        }
        return $args;
    }

    /**
     * Only local capability-gated getters: never fetch topology from the server.
     * If context cannot be read, perform a fresh scan without retaining a cursor.
     * @return list<mixed>|null
     */
    private function context(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): ?array
    {
        $context = [$config->isCluster(), $config->getMaxKeys(), $config->getShards()];
        try {
            foreach ([Redis::OPT_PREFIX, Redis::OPT_SCAN] as $option) {
                $context[] = $this->invokeClient($client, 'getOption', [$option]);
            }
            foreach (['getDBNum', 'getHost', 'getPort', 'isConnected'] as $method) {
                if (!method_exists($client, $method)) {
                    continue;
                }
                $value = $this->invokeClient($client, $method, []);
                if ($method === 'isConnected' && $value !== true) {
                    return null;
                }
                $context[] = $value;
            }
        } catch (\Throwable) {
            ScriptLogger::comment($this->name() . ' context unavailable; cursor will not be retained');
            return null;
        }
        return $context;
    }
}
