<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyCommand;

class persist extends KeyCommand {
    public function flags(): int {
        return self::WRITE;
    }

    public function type(): string {
        return self::ANY;
    }
}
