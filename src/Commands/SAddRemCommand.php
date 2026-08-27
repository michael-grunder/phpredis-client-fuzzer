<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class SAddRemCommand extends KeyMemsCommand {
    public function flags(): int {
        return self::READ | self::INVALIDATING;
    }

    public function type(): string {
        return self::SET;
    }
}
