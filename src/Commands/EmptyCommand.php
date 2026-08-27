<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class EmptyCommand extends Command implements FuzzInterface,
                                              FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::NONE;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        if ($this->isCluster($client)) {
            return $this->$fn($client, $config->getRandomKey(self::ANY));
        } else {
            return $this->$fn($client);
        }
    }
}
