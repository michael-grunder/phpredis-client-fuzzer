<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\HashFieldListCommand;

class hpersist extends HashFieldListCommand {
    public function flags(): int {
        return self::WRITE | self::EXPIRE | self::INVALIDATING;
    }
}
