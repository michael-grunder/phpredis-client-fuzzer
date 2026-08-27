<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyScanCommand;

class hscan extends KeyScanCommand {
    public function type(): string {
        return self::HASH;
    }
}
