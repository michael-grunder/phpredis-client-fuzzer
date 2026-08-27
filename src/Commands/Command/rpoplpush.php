<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyKeyCommand;

class rpoplpush extends KeyKeyCommand {
    public function type(): string {
        return self::LIST;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }
}
