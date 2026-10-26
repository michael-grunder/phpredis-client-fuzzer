<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyCommand;

class touch extends KeyCommand {
    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ;
    }
}
