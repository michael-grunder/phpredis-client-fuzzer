<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyScanCommand;

class zscan extends KeyScanCommand {
    public function type(): string {
        return self::ZSET;
    }
}
