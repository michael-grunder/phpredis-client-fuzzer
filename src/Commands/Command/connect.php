<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;

class connect extends Command {
    public function flags(): int {
        return self::CONNECTION;
    }

    public function type(): string {
        return self::NONE;
    }
}
