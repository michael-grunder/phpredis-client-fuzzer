<?php

namespace Mgrunder\RedisClientTester\Commands;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

interface FuzzInterface {
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed;
}
