<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Data\Cities;
use Mgrunder\PhpredisCommandFuzzer\Data\City;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\GeoRadius;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class GeoRadiusCommand extends Command implements FuzzInterface,
                                                               FuzzRawInterface
{
    use GeoRadius;

    public function type(): string {
        return self::GEO;
    }

    /** @return array{string, float, float, float, string, array<mixed>|null} */
    private function arguments(FuzzConfig $config): array {
        $city    = Cities::instance()->randomCity();
        $unit    = array_rand(self::UNITS);
        $radius  = rand(1, self::EARTH_RADIUS) * self::UNITS[$unit];

        return [
            $config->getRandomKey($this->type()),
            $city->lng(),
            $city->lat(),
            $radius,
            $unit,
            self::randomOptions($config, !!($this->flags() & self::WRITE)),
        ];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = $this->arguments($config);
        if ($args[5] === null)
            array_pop($args);

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$key, $longitude, $latitude, $radius, $unit, $options] =
            $this->arguments($config);

        return $this->execRaw(
            $client,
            $key,
            $longitude,
            $latitude,
            $radius,
            $unit,
            ...self::optionsToRawTokens($options),
        );
    }
}
