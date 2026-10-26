<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;

class connect extends Command {
    public function flags(): int {
        return self::CONNECTION;
    }

    public function type(): string {
        return self::NONE;
    }
}
