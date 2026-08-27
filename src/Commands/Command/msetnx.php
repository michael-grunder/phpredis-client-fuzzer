<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\MSetCommand;

class msetnx extends MSetCommand {
    public function flags(): int {
        /* PhpRedis and Relay split MSETNX across cluster nodes by slot */
        return parent::flags() | self::CROSSSLOT;
    }
}
