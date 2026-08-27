<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class IncrDecrByCommand extends Command implements FuzzInterface,
                                                            FuzzRawInterface
{
    abstract public function by(): int|float;

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $this->by(),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            $this->by(),
        );
    }
}
