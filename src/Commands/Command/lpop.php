<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ListPopCommand;

class lpop extends ListPopCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
