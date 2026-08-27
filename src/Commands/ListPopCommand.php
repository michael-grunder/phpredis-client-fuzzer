<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class ListPopCommand extends Command implements FuzzInterface,
                                                         FuzzRawInterface
{
    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::LIST;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            ...rand(1, 2) == 1 ? [rand(1, $config->getMembers())] : []
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            ...rand(1, 2) == 1 ? [rand(1, $config->getMembers())] : []
        );
    }
}
