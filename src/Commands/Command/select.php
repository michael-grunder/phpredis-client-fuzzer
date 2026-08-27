<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class select extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function flags(): int {
        return self::SELECT;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn($client, $config->getRandomDB());
    }
}
