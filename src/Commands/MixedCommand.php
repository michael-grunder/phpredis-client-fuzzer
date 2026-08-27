<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class MixedCommand extends ValueCommand {
    protected function randomValue(Redis|RedisCluster|Relay|Cluster $client,
                                            FuzzConfig $config): mixed
    {
        return match(rand(0, 4)) {
            0 => $config->getRandomString(),
            1 => $config->getRandomMember($this->type()),
            2 => $config->getRandomMembers($this->type()),
            3 => $config->getRandomFloat(),
            4 => $config->getRandomValues($client, $this->type()),
        };
    }
}
