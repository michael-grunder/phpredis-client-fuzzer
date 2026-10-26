<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\IncrDecrByCommand;

class decrby extends IncrDecrByCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function type(): string {
        return self::INT;
    }

    public function by(): int|float {
        return rand(PHP_INT_MIN, PHP_INT_MAX);
    }
}
