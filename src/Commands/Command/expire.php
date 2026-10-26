<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ExpireCommand;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class expire extends ExpireCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function getInt(Redis|RedisCluster|Relay|Cluster $client): int {
        return rand(1, 1000);
    }
}
