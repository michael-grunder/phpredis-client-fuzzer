<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Data\Cities;
use Mgrunder\PhpredisCommandFuzzer\Data\City;

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
