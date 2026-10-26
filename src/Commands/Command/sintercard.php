<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ProxyInterface;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\Command;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class sintercard extends Command implements FuzzInterface,
                                            FuzzRawInterface {
    public function type(): string {
        return self::SET;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [];

        $keys = $config->getRandomKeys($this->type());

        $args[] = count($keys);
        foreach ($keys as $key)
            $args[] = $key;

        if (rand() & 1) {
            $args[] = 'LIMIT';
            $args[] = rand(0, $config->getMembers());
        }

        return $this->execRaw($client, ...$args);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $keys = $config->getRandomKeys($this->type());

        if (rand() & 1)
            return $this->exec($client, $keys);
        else
            return $this->exec($client, $keys, rand(0, $config->getMembers()));
    }
}
