<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class BlockingListPopCommand extends Command implements FuzzInterface,
                                                                 FuzzRawInterface
{
    public function type(): string {
        return self::LIST;
    }

    public function flags(): int {
        return self::WRITE | self::BLOCKING | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $keys = $config->getRandomKeys($this->type());
        $secs = $config->getTimeoutFloat();

        if (rand(1, 2) == 1) {
            $keys[] = $secs;
            return $this->exec($client, ...$keys);
        } else {
            return $this->exec($client, $keys, $secs);
        }
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args   = $config->getRandomKeys($this->type());
        $args[] = $config->getTimeoutFloat();

        return $this->execRaw($client, ...$args);
    }
}
