<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\HashFieldListCommand;

class hexpiretime extends HashFieldListCommand {
    public function flags(): int {
        return self::READ;
    }
}
