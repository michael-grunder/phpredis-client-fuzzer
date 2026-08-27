<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Data\Cities;
use Mgrunder\PhpredisCommandFuzzer\Data\City;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\GeoRadius;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class GeoRadiusCommand extends Command implements FuzzInterface {
    use GeoRadius;

    public function type(): string {
        return self::GEO;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $city    = Cities::instance()->randomCity();
        $unit    = array_rand(self::UNITS);
        $radius  = rand(1, self::EARTH_RADIUS) * self::UNITS[$unit];

        $rng     = self::randomOptions($config, !!($this->flags() & self::WRITE));
        $options = $rng ? [$rng] : [];

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $city->lng(),
            $city->lat(),
            $radius,
            $unit,
            ...$options,
        );
    }
}
