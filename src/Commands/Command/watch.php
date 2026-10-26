<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class watch extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ | self::STATEFUL;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $keys = $config->getRandomKeys($this->type());

        if ($fn != 'execRaw' && rand() & 1) {
            return $this->$fn($client, $keys);
        } else {
            return $this->$fn($client, ...$keys);
        }
    }
}
