<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;


use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class ZRankCommand extends Command implements FuzzInterface,
                                                       FuzzRawInterface
{
    use Traits\FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomMember($this->type())
        );
    }
}
