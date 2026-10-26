<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyMemCommand;

class sismember extends KeyMemCommand {
    public function type(): string {
        return 'set';
    }

    public function flags(): int {
        return self::READ | self::CACHED | self::COMMON;
    }
}
