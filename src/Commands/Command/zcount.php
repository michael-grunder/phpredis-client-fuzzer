<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zcount extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $min = $config->getRandomFloat();
        $max = $config->getRandomFloat();

        if ($max < $min)
            [$max, $min] = [$min, $max];

        if (rand(1, 2) == 1)
            $min = '-inf';
        if (rand(1, 2) == 1)
            $max = '+inf';

        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $min,
            $max
        );
    }
}
