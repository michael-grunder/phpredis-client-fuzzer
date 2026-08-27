<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class ZPopCommand extends Command implements FuzzInterface, FuzzRawInterface {
    use Traits\FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    /**
     * @return list<int>
     */
    private function countArg(int $max): array {
        return match (rand(0, 1)) {
            0 => [],
            1 => [rand(0, max($max, 2) - 1)],
        };
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            ...$this->countArg($config->getMaxLen()),
        );
    }
}
