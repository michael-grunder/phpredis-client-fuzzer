<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Utilities;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class addignorepatterns extends Command implements FuzzInterface {
    private const CLEAR_CHANCE = 0.7;

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::LOCAL;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if (Utilities::randomChance(self::CLEAR_CHANCE))
            return $this->cmd($client, 'setOption', Relay::OPT_IGNORE_PATTERNS, []);

        for ($i = 0; $i < rand(1, 4); $i++) {
            $pattern = '*' . rand(1, 9) . '*';
            $patterns[$pattern] = true;
        }

        return $this->exec($client, ...array_keys($patterns));
    }
}
