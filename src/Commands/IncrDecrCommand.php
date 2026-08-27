<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class IncrDecrCommand extends Command implements FuzzInterface,
                                                          FuzzRawInterface
{
    public function type(): string {
        return self::INT;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomInt()
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomInt()
        );
    }
}
