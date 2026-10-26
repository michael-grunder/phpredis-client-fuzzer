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

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class geoadd extends Command implements FuzzInterface, FuzzRawInterface {
    /* All options permutations */
    private const OPTIONS = [
        null, ['NX'], ['XX'], ['CH'], ['NX', 'CH'], ['XX', 'CH'],
    ];

    public function type(): string {
        return self::GEO;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args   = [$config->getRandomKey($this->type())];
        $count  = rand(1, $config->getMembers());
        $cities = Cities::instance()->randomCities($count);

        foreach ($cities as $city) {
            $args[] = $city->lng();
            $args[] = $city->lat();
            $args[] = $city->name();
        }

        $options = self::OPTIONS[array_rand(self::OPTIONS)];
        if ($options !== null)
            $args[] = $options;

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKey($this->type())];
        $opts = self::OPTIONS[array_rand(self::OPTIONS)];

        if ($opts)
            $args = array_merge($args, $opts);

        $count  = rand(1, $config->getMembers());
        $cities = Cities::instance()->randomCities($count);

        foreach ($cities as $city) {
            $args[] = $city->lng();
            $args[] = $city->lat();
            $args[] = $city->name();
        }

        return $this->execRaw($client, ...$args);
    }
}
