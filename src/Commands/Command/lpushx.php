<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ListPushCommand;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class lpushx extends ListPushCommand {
    protected function maxElements(Redis|RedisCluster|Relay|Cluster $client): int {
        return 1;
    }
}
