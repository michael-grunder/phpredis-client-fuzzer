<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\LocalCommand;

class crash extends LocalCommand {

    public function flags(): int {
        return parent::flags() | self::CRASH;
    }
}
