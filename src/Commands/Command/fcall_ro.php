<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FCallCommand;

class fcall_ro extends FCallCommand {
    public function flags(): int {
        return self::READ;
    }
}
