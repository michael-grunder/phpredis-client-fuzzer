<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class _prefix extends Command implements FuzzInterface {
    public function flags(): int {
        return self::LOCAL;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if ( ! method_exists($client, $this->name()))
            return false;

        return $this->exec($client, $config->getRandomValue($client, $this->type()));
    }
}
