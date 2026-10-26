<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\HashFieldListCommand;

class hgetdel extends HashFieldListCommand {
    public function flags(): int {
        return self::READ | self::WRITE | self::DELETE | self::INVALIDATING;
    }
}
