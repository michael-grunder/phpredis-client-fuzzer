<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\PingCommand;

class ping extends PingCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    protected function argOptional(): bool {
        return true;
    }
}
