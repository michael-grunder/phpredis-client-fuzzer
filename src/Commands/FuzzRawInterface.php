<?php

namespace Mgrunder\RedisClientTester\Commands;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

interface FuzzRawInterface {
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed;
}
