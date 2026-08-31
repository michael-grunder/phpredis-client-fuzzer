<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Data\Cities;
use Mgrunder\PhpredisCommandFuzzer\Data\City;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class GeoPosHashCommand extends Command implements FuzzInterface,
                                                                FuzzRawInterface
{
    use Traits\FuzzGeneric;

    public function type(): string {
        return self::GEO;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $count = rand(1, $config->getMembers());
        $names = array_map(
            fn($c) => $c->name(),
            Cities::instance()->randomCities($count)
        );

        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            ...$names
        );
    }
}
