<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\HashFieldListCommand;

class hexpiretime extends HashFieldListCommand {
    public function flags(): int {
        return self::READ;
    }
}
