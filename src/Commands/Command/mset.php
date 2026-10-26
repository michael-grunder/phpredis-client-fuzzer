<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\MSetCommand;

class mset extends MSetCommand {
    public function flags(): int {
        /* PhpRedis and Relay split MSET across cluster nodes by slot */
        return parent::flags() | self::CROSSSLOT | self::COMMON;
    }
}
