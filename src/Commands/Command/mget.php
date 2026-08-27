<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class mget extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return Command::STRING;
    }

    public function flags(): int {
        /* PhpRedis and Relay split MGET across cluster nodes by slot */
        return Command::READ | self::CACHED | self::CROSSSLOT;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec($client, $config->getRandomKeys($this->type()));
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw($client, ...$config->getRandomKeys($this->type()));
    }
}
