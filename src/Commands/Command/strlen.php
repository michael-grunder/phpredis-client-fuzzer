<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyCommand;

class strlen extends KeyCommand {
    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function type(): string {
        return self::STRING;
    }
}
