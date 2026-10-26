<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\ProxyInterface;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\ZRangeArgs;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class zrangestore extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = new ZRangeArgs($config, allowWithScores: false);

        return $this->exec(
            $client,
            'dst-' . $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
            $args->start(), $args->end(), $args->options(),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = new ZRangeArgs($config, allowWithScores: false);

        return $this->execRaw(
            $client,
            'dst-' . $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
            $args->start(), $args->end(), ...$args->optionsRaw(),
        );
    }
}
