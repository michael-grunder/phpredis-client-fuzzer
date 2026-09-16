<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ExpireCommand;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class expireat extends ExpireCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function getInt(Redis|RedisCluster|Relay|Cluster $client): int {
        return time() + rand(1, 60);
    }
}
