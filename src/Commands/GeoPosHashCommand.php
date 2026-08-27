<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Data\Cities;
use Mgrunder\PhpredisCommandFuzzer\Data\City;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class GeoPosHashCommand extends Command implements FuzzInterface {
    public function type(): string {
        return self::GEO;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $count = rand(1, $config->getMembers());
        $names = array_map(
            fn($c) => $c->name(),
            Cities::instance()->randomCities($count)
        );

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            ...$names
        );
    }
}
