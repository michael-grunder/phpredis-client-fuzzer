<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ZRangeByScoreCommand;

class zrangebyscore extends ZRangeByScoreCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    protected function reverse(): bool {
        return false;
    }
}
