<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyCommand;

class ttl extends KeyCommand {
    public function flags(): int {
        return self::READ | self::COMMON;
    }

    public function type(): string {
        return self::ANY;
    }
}

