<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class smismember extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return 'set';
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzzGeneric(Redis|Relay|RedisCluster|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            ...$config->getRandomMembers($this->type()),
        );
    }
}
