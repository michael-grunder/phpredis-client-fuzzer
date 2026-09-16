<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyScanCommand;

class sscan extends KeyScanCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function type(): string {
        return self::SET;
    }
}
