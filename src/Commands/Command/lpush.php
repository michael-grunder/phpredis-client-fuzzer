<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListPushCommand;

class lpush extends ListPushCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
