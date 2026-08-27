<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyMemCommand;

class sismember extends KeyMemCommand {
    public function type(): string {
        return 'set';
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }
}
