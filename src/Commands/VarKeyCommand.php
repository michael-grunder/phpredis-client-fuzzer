<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class VarKeyCommand extends Command implements FuzzInterface,
                                                        FuzzRawInterface
{
    abstract protected function takesArray(): bool;

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec($client, ...$config->getRandomKeys($this->type()));
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw($client, ...$config->getRandomKeys($this->type()));
    }
}
