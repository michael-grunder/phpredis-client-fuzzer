<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ExpireCommand;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class expire extends ExpireCommand {
    public function getInt(Redis|RedisCluster|Relay|Cluster $client): int {
        return rand(1, 1000);
    }
}
