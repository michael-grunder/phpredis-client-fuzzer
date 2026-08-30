<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\GeoRadius;
use Mgrunder\PhpredisCommandFuzzer\Data\Cities;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

abstract class GeoRadiusByMemberCommand extends Command implements FuzzInterface,
                                                                  FuzzRawInterface
{
    use GeoRadius;

    public function type(): string {
        return self::GEO;
    }

    /** @return array{string, string, float, string, array<mixed>|null} */
    private function arguments(FuzzConfig $config): array {
        $unit = self::randomUnit();

        return [
            $config->getRandomKey($this->type()),
            Cities::instance()->randomCity()->name(),
            self::randomDistance($unit),
            $unit,
            self::randomOptions($config, ($this->flags() & self::WRITE) !== 0),
        ];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = $this->arguments($config);

        if ($args[4] === null && rand() & 1)
            array_pop($args);

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$key, $member, $radius, $unit, $options] = $this->arguments($config);

        return $this->execRaw(
            $client,
            $key,
            $member,
            $radius,
            $unit,
            ...self::optionsToRawTokens($options),
        );
    }
}
