<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\HashFieldListCommand;

class hgetdel extends HashFieldListCommand {
    public function flags(): int {
        return self::READ | self::WRITE | self::DELETE | self::INVALIDATING;
    }
}
