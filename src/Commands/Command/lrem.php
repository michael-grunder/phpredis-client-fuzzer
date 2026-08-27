<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class lrem extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::LIST;
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
            $config->getRandomValue($client, $this->type()),
            rand(0, 100),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            rand(0, 100),
            $config->getRandomString(),
        );
    }
}
