<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class copy extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $type = $this->randomType();
        $args = [$config->getRandomKey($type), $config->getRandomKey($type)];

        $args[] = match(rand(1, 4)) {
            1 => null,
            2 => ['REPLACE' => rand(0, 1)],
            3 => ['DB' => rand(1,8)],
            4 => ['DB' => rand(1, 8), 'REPLACE' => rand(0, 1)],
        };

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $type = $this->randomType();
        $args = [$config->getRandomKey($type), $config->getRandomKey($type)];

        $rand = rand();

        if ($rand & 1) {
            $args[] = 'DB';
            $args[] = $config->getRandomDB();
        }

        if ($rand & 2)
            $args[] = 'REPLACE';

        return $this->execRaw($client, ...$args);
    }
}
