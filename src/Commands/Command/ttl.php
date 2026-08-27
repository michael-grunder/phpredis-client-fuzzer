<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyCommand;

class ttl extends KeyCommand {
    public function flags(): int {
        return self::READ;
    }

    public function type(): string {
        return self::ANY;
    }
}

