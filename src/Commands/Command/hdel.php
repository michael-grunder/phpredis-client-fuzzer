<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class hdel extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::HASH;
    }

    public function flags(): int {
        return self::READ | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            ...$config->getRandomMembers($this->type())
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            ...$config->getRandomMembers($this->type())
        );
    }
}
