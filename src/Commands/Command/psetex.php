<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SetExCommand;


class psetex extends SetExCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function getTimeout(int $seconds): int {
        return $seconds * 1000;
    }
}
