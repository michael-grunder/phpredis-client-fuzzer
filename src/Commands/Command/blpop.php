<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\BlockingListPopCommand;

class blpop extends BlockingListPopCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
