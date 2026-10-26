<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\MZSetCommand;

class zinter extends MZSetCommand {
    public function flags(): int {
        return self::READ;
    }
}
