<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class SInterUnionDiffStoreCommand extends Command implements FuzzInterface,
                                                                       FuzzRawInterface
{
    use Traits\FuzzGeneric;

    public function type(): string {
        return self::SET;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $dst = 'dst-' . $config->getRandomKey($this->type());

        return $this->$fn(
            $client, $dst, ...$config->getRandomKeys($this->type()),
        );
    }
}
