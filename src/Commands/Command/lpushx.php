<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListPushCommand;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class lpushx extends ListPushCommand {
    protected function maxElements(Redis|RedisCluster|Relay|Cluster $client): int {
        return 1;
    }
}
