<?php

namespace Mgrunder\RedisClientTester\Commands\Command;


use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

/* A bit of a meta-fuzz testing class which exists to use rawCommand with a
 * random number of arguments, to fuzz improper command execution */
class rawcommand extends Command implements FuzzRawInterface {
    private const COMMANDS = [
        'get' => 1, 'smembers' => 1, 'hgetall' => 1, 'lrange' => 1
    ];

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ | self::WRITE;
    }

    private function randomArg(FuzzConfig $config): mixed {
        return match (rand() % 4) {
            0 => $config->getRandomKey(self::ANY),
            1 => $config->getRandomString(),
            2 => $config->getRandomInt(),
            3 => $config->getRandomFloat(),
        };
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $obj = Command::object(array_rand(self::COMMANDS));
        $rng = rand() % 8;

        if (($client instanceOf Cluster) || ($client instanceOf RedisCluster))
            $args = [$this->clusterRawRoutingKey(), $obj->name()];
        else
            $args = [$obj->name()];

        while ($rng--)
            $args[] = $this->randomArg($config);

        return $this->exec($client, ...$args);
    }
}
