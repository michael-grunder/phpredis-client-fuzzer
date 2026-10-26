<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ZRangeByScoreCommand;

class zrevrangebyscore extends ZRangeByScoreCommand {
    protected function reverse(): bool {
        return true;
    }
}
