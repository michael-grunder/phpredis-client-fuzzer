<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class unwatch extends Command implements FuzzInterface,
                                         FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::NONE;
    }

    public function flags(): int {
        return self::READ | self::STATEFUL;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        if ($fn == 'execRaw' && $this->isCluster($client)) {
            return $this->$fn($client, $config->getRandomKey(self::ANY));
        }

        return $this->$fn($client);
    }
}
