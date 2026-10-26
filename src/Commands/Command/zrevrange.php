<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zrevrange extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ | self::CACHED | self::COMMON;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $options = match(rand(1, 7)) {
            1 => null,
            2 => true,
            3 => ['withscores'],
            4 => ['withscores' => false],
            5 => ['withscores' => true],
            6 => false,
            7 => [],
        };

        [$start, $end] = $config->randomRange();

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $start, $end, ...(rand(0, 3) === 0 ? [] : [$options])
        );
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$key, [$start, $end]] = [
            $config->getRandomKey($this->type()), $config->randomRange()
        ];

        $withscores = rand() & 1 ? ['WITHSCORES'] : [];

        return $this->execRaw($client, $key, $start, $end, ...$withscores);
    }
}
