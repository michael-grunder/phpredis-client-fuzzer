<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyCommand;

class smembers extends KeyCommand {
    public function flags(): int {
        return self::READ | self::CACHED | self::COMMON;
    }

    public function type(): string {
        return self::SET;
    }
}
