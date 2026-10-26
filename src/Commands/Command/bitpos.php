<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class bitpos extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            rand() & 1,
        ];

        $rng = rand(0, 4);
        if ($rng--)
            $args[] = rand(0, $config->getMaxLen());
        if ($rng--)
            $args[] = rand(0, $config->getMaxLen());
        if ($rng--)
            $args[] = $rng > 0;

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            rand(0, $config->getMaxLen()),
        ];

        $rng = rand();
        if ($rng & 1) {
            [$start, $end] = $config->randomRange();
            $args[] = $start;
            if ($rng & 2) {
                $args[] = $end;
                if ($rng & 4)
                    $args[] = ($rng & 8) ? 'BYTE' : 'BIT';
            }
        }

        return $this->execRaw($client, ...$args);
    }
}
