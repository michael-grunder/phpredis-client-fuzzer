<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ExpireCommand;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class pexpireat extends ExpireCommand {
    public function getInt(Redis|RedisCluster|Relay|Cluster $client): int {
        return time() + rand(1, 600000);
    }
}
