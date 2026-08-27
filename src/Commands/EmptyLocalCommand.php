<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class EmptyLocalCommand extends Command implements FuzzInterface {
    public function type(): string {
        return self::NONE;
    }

    public function flags(): int {
        return self::READ | self::LOCAL;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if ( ! method_exists($client, $this->name()))
            return null;

        return $this->exec($client);
    }
}
