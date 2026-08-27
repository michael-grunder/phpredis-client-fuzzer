<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class RenameCommand extends Command implements FuzzInterface,
                                                        FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $type = $this->randomType();

        return $this->$fn(
            $client,
            $config->getRandomKey($type),
            $config->getRandomKey($type),
        );
    }
}
