<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\IncrDecrByCommand;

class incrby extends IncrDecrByCommand {
    private const MIN = -2147483648;
    private const MAX = 2147483647;

    public function type(): string {
        return self::INT;
    }

    public function by(): int|float {
        return rand(self::MIN, self::MAX);
    }
}
