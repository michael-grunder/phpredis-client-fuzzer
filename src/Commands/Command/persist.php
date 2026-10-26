<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyCommand;

class persist extends KeyCommand {
    public function flags(): int {
        return self::WRITE;
    }

    public function type(): string {
        return self::ANY;
    }
}
