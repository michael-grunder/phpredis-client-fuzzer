<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyCommand;

// Additional single key commands
class dump extends keycommand {
    public function flags(): int {
        return self::READ;
    }

    public function type(): string {
        return self::ANY;
    }
}
