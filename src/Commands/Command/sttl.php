<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SetMemberListCommand;

class sttl extends SetMemberListCommand {
    public function flags(): int {
        return self::READ;
    }
}
