<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class ValueCommand extends Command implements FuzzInterface {
    abstract protected function randomValue(Redis|RedisCluster|Relay|Cluster $client,
                                            FuzzConfig $config): mixed;

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec($client, $this->randomValue($client, $config));
    }
}
