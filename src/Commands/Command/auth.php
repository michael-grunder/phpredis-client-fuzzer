<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;

class auth extends Command {
    public function flags(): int {
        return 0;
    }

    public function type(): string {
        return self::NONE;
    }
}
