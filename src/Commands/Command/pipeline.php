<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class pipeline extends Command implements FuzzInterface {
    public function flags(): int {
        return self::READ | self::STATEFUL;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        try {
            if (($client instanceOf RedisCluster) || ($client instanceOf Cluster))
                return false;

            if ($client->getMode() != Redis::ATOMIC)
                return false;

            return $this->exec($client);
        } catch (\Exception $ex) {
            return false;
        }
    }
}
