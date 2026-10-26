<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\MSetCommand;

class msetnx extends MSetCommand {
    public function flags(): int {
        /* PhpRedis and Relay split MSETNX across cluster nodes by slot */
        return parent::flags() | self::CROSSSLOT;
    }
}
