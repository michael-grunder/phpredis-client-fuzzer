<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\IncrDecrByCommand;

class incrbyfloat extends IncrDecrByCommand {
    public function type(): string {
        return self::FLOAT;
    }

    public function by(): int|float {
        return ((mt_rand() / mt_getrandmax()) * PHP_INT_MAX) * rand(0, 1) == 0 ? 1 : -1;
    }
}
