<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\SAddRemCommand;

class srem extends SAddRemCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
