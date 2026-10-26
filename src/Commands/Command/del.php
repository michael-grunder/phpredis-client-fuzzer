<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\VarKeyCommand;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class del extends VarKeyCommand {
    public function type(): string {
        return Command::ANY;
    }

    public function flags(): int {
        /* PhpRedis and Relay split DEL across cluster nodes by slot */
        return Command::WRITE | Command::DELETE | self::INVALIDATING |
               self::CROSSSLOT | self::COMMON;
    }

    protected function takesArray(): bool {
        return false;
    }
}
