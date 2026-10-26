<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class flushmemory extends Command implements FuzzInterface {
    public function flags(): int {
        return self::WRITE | self::FLUSH | self::LOCAL;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if ( !($client instanceOf Relay))
            return false;

        return $this->exec($client);
    }
}
