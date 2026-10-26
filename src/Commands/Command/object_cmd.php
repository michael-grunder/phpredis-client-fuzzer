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

class object_cmd extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    private const OPS = [
        'ENCODING' => true,
        'FREQ'     => true,
        'IDLETIME' => true,
        'REFCOUNT' => true,
    ];

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ | self::ADMIN;
    }

    public function name(): string {
        return 'object';
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed {
        return $this->$fn(
            $client,
            array_rand(self::OPS),
            $config->getRandomKey($this->type()),
        );
    }
}
