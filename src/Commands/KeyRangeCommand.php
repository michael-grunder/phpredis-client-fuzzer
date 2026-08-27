<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class KeyRangeCommand extends Command implements FuzzInterface,
                                                          FuzzRawInterface
{
    abstract public function len(Redis|Relay $client, string $key): ?int;

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            ...$this->randomRange($config->getMembers())
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            ...$this->randomRange($config->getMembers())
        );
    }
}
