<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyCommand;

class digest extends KeyCommand {
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ;
    }
}
