<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Traits;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

trait FuzzGeneric {
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        /** @phpstan-ignore-next-line */
        return $this->fuzzGeneric($client, $config, 'exec');
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        /** @phpstan-ignore-next-line */
        return $this->fuzzGeneric($client, $config, 'execRaw');
    }
}
