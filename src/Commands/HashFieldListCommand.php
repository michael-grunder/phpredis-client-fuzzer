<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

abstract class HashFieldListCommand extends Command implements FuzzInterface,
                                                               FuzzRawInterface
{
    public function type(): string {
        return self::HASH;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomMembers($this->type()),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $fields = $config->getRandomMembers($this->type());

        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            'FIELDS',
            count($fields),
            ...$fields,
        );
    }
}
