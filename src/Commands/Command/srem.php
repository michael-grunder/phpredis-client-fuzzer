<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SAddRemCommand;

class srem extends SAddRemCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
