<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;

class auth extends Command {
    public function flags(): int {
        return 0;
    }

    public function type(): string {
        return self::NONE;
    }
}
