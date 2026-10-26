<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zrandmember extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
    }

    private function randomCount(FuzzConfig $config): int
    {
        return match (rand(0, 2)) {
            0 => 0,
            1 => rand(1, $config->getMembers()),
            2 => -rand(1, $config->getMembers()),
        };
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $nulls = [null, []];

        $options = match(rand(1, 6)) {
            1 => $nulls[array_rand($nulls)],
            2 => ['WITHSCORES' => true, 'COUNT' => 1],
            3 => ['COUNT' => $this->randomCount($config)],
            4 => ['WITHSCORES' => true, 'COUNT' => $this->randomCount($config)],
            5 => ['COUNT' => 0],
            6 => ['WITHSCORES' => false, 'COUNT' => $this->randomCount($config)],
        };

        $args = [$config->getRandomKey($this->type())];
        if (rand(0, 3) !== 0) {
            $args[] = $options;
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKey($this->type())];
        $rand = rand();

        if ($rand & 1) {
            $args[] = $this->randomCount($config);
            if ($rand & 2) {
                $args[] = 'WITHSCORES';
            }
        }

        return $this->execRaw($client, ...$args);
    }
}
