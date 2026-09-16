<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListPopCommand;

class rpop extends ListPopCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
