<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\GeoRadius;
use Mgrunder\PhpredisCommandFuzzer\Data\Cities;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class GeoSearchCommand extends Command implements FuzzInterface,
                                                               FuzzRawInterface
{
    use GeoRadius {
        randomOptions as randomGeoOptions;
    }

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

    /** @return array<mixed>|null */
    private function randomSearchOptions(FuzzConfig $config): ?array {
        if (($this->flags() & self::WRITE) === 0)
            return self::randomGeoOptions($config, false);

        $rng = rand();
        $options = [];

        if ($rng & 0x1)
            $options[] = $rng & 0x2 ? 'ASC' : 'DESC';
        if ($rng & 0x4)
            $options['COUNT'] = rand(1, $config->getMembers());
        if ($rng & 0x8)
            $options[] = 'STOREDIST';

        return $options ?: null;
    }

    /** @return array{list<mixed>, array{0: float, 1: float}|string, array{0: float, 1: float}|float, string, array<mixed>|null} */
    private function arguments(FuzzConfig $config): array {
        $unit = self::randomUnit();
        $args = [];

        if ($this->flags() & self::WRITE) {
            $args[] = $config->getRandomKey(Command::ZSET);
        }

        $position = self::randomPosition();
        $shape = self::randomShape($unit);
        $args[] = $config->getRandomKey($this->type());

        return [$args, $position, $shape, $unit, $this->randomSearchOptions($config)];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        [$args, $position, $shape, $unit, $options] = $this->arguments($config);
        array_push($args, $position, $shape, $unit);

        if ($options)
            $args[] = $options;

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$args, $position, $shape, $unit, $options] = $this->arguments($config);

        if (is_array($position)) {
            array_push($args, 'FROMLONLAT', $position[0], $position[1]);
        } else {
            array_push($args, 'FROMMEMBER', $position);
        }

        if (is_array($shape)) {
            array_push($args, 'BYBOX', $shape[0], $shape[1], $unit);
        } else {
            array_push($args, 'BYRADIUS', $shape, $unit);
        }

        array_push($args, ...self::optionsToRawTokens($options));

        return $this->execRaw($client, ...$args);
    }
}
