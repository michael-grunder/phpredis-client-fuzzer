<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\HashFieldListCommand;

class hpersist extends HashFieldListCommand {
    public function flags(): int {
        return self::WRITE | self::EXPIRE | self::INVALIDATING;
    }
}
