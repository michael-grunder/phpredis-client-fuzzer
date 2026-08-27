<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\IncrDecrCommand;

class decr extends IncrDecrCommand {
    public function type(): string {
        return self::INT;
    }
}
