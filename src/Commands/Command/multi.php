<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class multi extends Command implements FuzzInterface {
    public function flags(): int {
        return self::READ | self::STATEFUL;
    }

    public function type(): string {
        return self::NONE;
    }

    private function canExec(Redis|RedisCluster|Relay|Cluster $client): bool {
        try {
            $mode = $this->isRelay($client)
                ? $client->getMode(true)
                : $client->getMode();

            return $mode == Redis::ATOMIC || $mode == Redis::PIPELINE;
        } catch (\Exception $ex) {
            return false;
        }
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if ( ! $this->canExec($client))
            return false;

        return $this->exec($client);
    }
}
