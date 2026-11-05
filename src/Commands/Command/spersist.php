<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SetMemberListCommand;

class spersist extends SetMemberListCommand {
    public function flags(): int {
        return self::WRITE | self::EXPIRE | self::INVALIDATING;
    }
}
