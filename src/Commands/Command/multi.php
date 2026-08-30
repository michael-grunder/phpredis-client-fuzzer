<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;

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
            if (!$this->isRelay($client))
                return $client->getMode() == Redis::ATOMIC;

            $mode = $client->getMode(true);

            return $mode == Relay::ATOMIC || $mode == Relay::PIPELINE;
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
