<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class pfcount extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::HLL;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        // Can take an array or string
        $keys = match(rand(1, 2)) {
            1 => $config->getRandomKey($this->type()),
            2 => $config->getRandomKeys($this->type()),
        };

        return $this->exec($client, $keys);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw($client, ...$config->getRandomKeys($this->type()));
    }
}
