<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyIntCommand;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

abstract class ExpireCommand extends KeyIntCommand {
    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::ANY;
    }
}
