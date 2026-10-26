<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\IncrDecrByCommand;

class incrby extends IncrDecrByCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

private const MIN = -2147483648;
    private const MAX = 2147483647;

    public function type(): string {
        return self::INT;
    }

    public function by(): int|float {
        return rand(self::MIN, self::MAX);
    }
}
