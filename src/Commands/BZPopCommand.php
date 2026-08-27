<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class BZPopCommand extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::BLOCKING | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());

        if (rand(1, 2) == 1) {
            return $this->exec($client, $keys, $config->getRandomTimeout());
        } else {
            $keys[] = $config->getRandomTimeout();
            return $this->exec($client, ...$keys);
        }
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args   = $config->getRandomKeys($this->type());
        $args[] = $config->getRandomTimeout();

        return $this->execRaw($client, ...$args);
    }
}
