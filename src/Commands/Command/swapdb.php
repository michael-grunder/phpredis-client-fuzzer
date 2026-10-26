<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class swapdb extends Command implements FuzzInterface {
    public function type(): string {
        return self::NONE;
    }

    public function flags(): int {
        return self::WRITE | self::ADMIN;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        return $this->exec(
            $client,
            $config->getRandomDB(),
            $config->getRandomDB(),
        );
    }
}
