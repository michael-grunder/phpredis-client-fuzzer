<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\ZRangeArgs;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zrange extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ | self::CACHED | self::COMMON;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        if (rand(0, 1)) {
            $args = new ZRangeArgs($config);

            return $this->exec($client, $key, $args->start(), $args->end(), $args->options());
        }

        // Legacy bool/null/omitted options require rank bounds.
        [$start, $end] = $config->randomRange();
        $options = match (rand(0, 4)) {
            0 => [],
            1 => [false],
            2 => [true],
            3 => [null],
            4 => [[]],
        };

        return $this->exec($client, $key, $start, $end, ...$options);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $args = new ZRangeArgs($config);

        return $this->execRaw(
            $client, $key, $args->start(), $args->end(), ...$args->optionsRaw()
        );
    }
}
