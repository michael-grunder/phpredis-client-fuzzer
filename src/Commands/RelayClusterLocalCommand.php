<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

abstract class RelayClusterLocalCommand extends Command implements FuzzInterface {
    public function flags(): int {
        return self::LOCAL;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if (!$client instanceof Cluster || !method_exists($client, $this->name()))
            return false;

        return $this->exec($client);
    }
}
