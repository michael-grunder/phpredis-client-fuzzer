<?php

namespace Mgrunder\RedisClientTester\Commands;

use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class KeyCommand extends Command implements FuzzInterface,
                                                     FuzzRawInterface
{
    use FuzzGeneric;

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn($client, $config->getRandomKey($this->type()));
    }
}
