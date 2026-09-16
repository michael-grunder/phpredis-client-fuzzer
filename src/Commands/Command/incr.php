<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\IncrDecrCommand;

class incr extends IncrDecrCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function type(): string {
        return self::INT;
    }
}
