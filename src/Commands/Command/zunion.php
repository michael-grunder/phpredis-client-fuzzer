<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\MZSetCommand;

class zunion extends MZSetCommand {
    public function flags(): int {
        return self::READ;
    }
}
