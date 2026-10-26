<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\ProxyInterface;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Data\Cities;
use Mgrunder\RedisClientTester\Data\City;

use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class geodist extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    private const OPTIONS = [null, 'M', 'KM', 'FT', 'MI'];

    public function type(): string {
        return self::GEO;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $args = [
             $config->getRandomKey($this->type()),
             Cities::instance()->randomCity()->name(),
            Cities::instance()->randomCity()->name(),
        ];

        $unit = self::OPTIONS[array_rand(self::OPTIONS)];
        if ($unit !== NULL)
             $args[] = $unit;

        return $this->$fn($client, ...$args);
    }
}
