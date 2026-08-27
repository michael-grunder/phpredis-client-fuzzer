<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class exec extends Command implements FuzzInterface {
    public function flags(): int {
        return self::READ;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        try {
            if ($client->getMode() == Redis::ATOMIC)
                return false;
        } catch (\Exception $ex) {
            return false;
        }

        return $this->exec($client);
    }
}
