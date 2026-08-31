<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class ScanCommand extends Command implements FuzzInterface, FuzzRawInterface {
    private int|string|null $cursor = null;
    private ?string $routeKey = null;
    protected ?string $key = null;
    protected ?string $type = null;
    protected ?string $pattern = null;

    abstract protected function initArgs(FuzzConfig $config): void;

    public function flags(): int {
        return self::READ | self::SCAN;
    }

    private function resetValues(): void {
        $this->cursor = null;
        $this->routeKey = null;
        $this->key = null;
        $this->type = null;
        $this->pattern = null;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if ($client->getMode() != Redis::ATOMIC)
            return false;

        /* A null cursor starts a new client iteration.  Relay treats an
           integer zero as an already completed iterator and returns false
           without issuing the command. */
        if ($this->cursor === null || $this->cursor === 0) {
            $this->resetValues();
            $this->initArgs($config);
        }

        $count = rand(0, $config->getCmdMaxKeys());
        $fn = $this->name();
        $cursor = $this->cursor;

        /* We can't use the normal $this->exec indirection here because the
           scan cursor is sent as a reference. */
        if ($this->key) {
            $args = [$this->key, &$cursor, $this->pattern, $count];
            ScriptLogger::logReference($client, $fn, $args, 1);
            $result = $this->invokeClient($client, $fn, $args);
        } else if ($client instanceOf Redis || $client instanceOf Relay) {
            $args = [&$cursor, $this->pattern, $count, $this->type];
            ScriptLogger::logReference($client, $fn, $args, 0);
            $result = $this->invokeClient($client, $fn, $args);
        } else {
            /* A cluster SCAN cursor belongs to one server.  Keep routing the
               iteration through the same generated key until it completes. */
            $this->routeKey ??= $config->getRandomKey(self::ANY);
            $args = [&$cursor, $this->routeKey, $this->pattern, $count,
                     $this->type];
            ScriptLogger::logReference($client, $fn, $args, 0);
            $result = $this->invokeClient($client, $fn, $args);
        }
        $this->cursor = $cursor;

        if ($client->getLastError())
            $this->logRedisError($client, ...$args);

        return $result;
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $this->resetValues();
        $this->initArgs($config);

        $args = [];
        if ($this->key !== null)
            $args[] = $this->key;
        $args[] = 0;

        if ($this->pattern !== null)
            array_push($args, 'MATCH', $this->pattern);

        $count = rand(0, $config->getCmdMaxKeys());
        if ($count > 0)
            array_push($args, 'COUNT', $count);

        if ($this->type !== null)
            array_push($args, 'TYPE', $this->type);

        return $this->execRaw($client, ...$args);
    }
}
