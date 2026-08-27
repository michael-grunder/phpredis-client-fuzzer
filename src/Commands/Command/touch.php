<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyCommand;

class touch extends KeyCommand {
    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ;
    }
}
