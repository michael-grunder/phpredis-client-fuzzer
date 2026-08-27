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

use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

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
