<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\VarKeyCommand;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class exists extends VarKeyCommand {
    public function type(): string {
        return Command::ANY;
    }

    public function flags(): int {
        return Command::READ | self::CACHED | self::COMMON;
    }

    protected function takesArray(): bool {
        return false;
    }
}
