<?php

namespace Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\IncrDecrCommand;

class decr extends IncrDecrCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function type(): string {
        return self::INT;
    }
}
