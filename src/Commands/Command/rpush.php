<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ListPushCommand;

class rpush extends ListPushCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
