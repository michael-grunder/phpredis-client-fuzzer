<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyCommand;

class digest extends KeyCommand {
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ;
    }
}
