<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyCommand;

class xlen extends KeyCommand {
    public function flags(): int {
        return self::READ;
    }

    public function type(): string {
        return 'stream';
    }
}

