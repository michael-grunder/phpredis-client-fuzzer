<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class ZRemRangeByCommand extends Command implements FuzzInterface,
                                                             FuzzRawInterface
{
    use Traits\FuzzGeneric;

    /**
     * @return array<mixed>
     */
    abstract public function getRange(FuzzConfig $config): array;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            ...$this->getRange($config),
        );
    }
}
