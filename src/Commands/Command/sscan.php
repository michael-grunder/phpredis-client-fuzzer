<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyScanCommand;

class sscan extends KeyScanCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function type(): string {
        return self::SET;
    }
}
