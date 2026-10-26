<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyCommand;

class strlen extends KeyCommand {
    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function type(): string {
        return self::STRING;
    }
}
