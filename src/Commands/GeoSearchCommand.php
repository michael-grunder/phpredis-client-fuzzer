<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\GeoRadius;
use Mgrunder\PhpredisCommandFuzzer\Data\Cities;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class GeoSearchCommand extends Command implements FuzzInterface {
    use GeoRadius;

    public function type(): string {
        return self::GEO;
    }

    /**
     * @return array{0: float, 1: float}|string
     */
    private function randomPosition(): array|string {
        $city = Cities::instance()->randomCity();
        return (rand() & 1) ? [$city->lng(), $city->lat()] : $city->name();
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $unit = self::randomUnit();
        $options = self::randomOptions($config, !!($this->flags() & self::WRITE));
        $args = [];

        if ($this->flags() & self::WRITE) {
            $args[] = $config->getRandomKey(Command::ZSET);
        }

        $args = array_merge($args, [
            $config->getRandomKey($this->type()),
            self::randomPosition(),
            self::randomShape($unit),
            $unit,
        ]);

        if ($options)
            $args[] = $options;

        return $this->exec($client, ...$args);
    }
}
