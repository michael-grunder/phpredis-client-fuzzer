<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyCommand;

class scard extends KeyCommand {
    public function flags(): int {
        return self::READ | self::CACHED | self::COMMON;
    }

    public function type(): string {
        return self::SET;
    }
}

