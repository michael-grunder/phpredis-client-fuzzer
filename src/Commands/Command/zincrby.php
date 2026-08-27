<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zincrby extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomFloat(),
            $config->getRandomMember($this->type())
        );
    }
}
