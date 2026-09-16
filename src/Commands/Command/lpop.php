<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListPopCommand;

class lpop extends ListPopCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
