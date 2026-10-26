<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\ZRankCommand;

class zrank extends ZRankCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
