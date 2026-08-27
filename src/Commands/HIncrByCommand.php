<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class HIncrByCommand extends Command implements FuzzInterface,
                                                         FuzzRawInterface
{
    use Traits\FuzzGeneric;

    abstract protected function getRandomMember(FuzzConfig $config): string;
    abstract protected function getRandomValue(FuzzConfig $config): int|float;

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::HASH;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $this->getRandomMember($config),
            $this->getRandomValue($config)
        );
    }
}
