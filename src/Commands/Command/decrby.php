<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\IncrDecrByCommand;

class decrby extends IncrDecrByCommand {
    public function type(): string {
        return self::INT;
    }

    public function by(): int|float {
        return rand(PHP_INT_MIN, PHP_INT_MAX);
    }
}
