<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SetMemberListCommand;

class spexpiretime extends SetMemberListCommand {
    public function flags(): int {
        return self::READ;
    }
}
