<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyScanCommand;

class zscan extends KeyScanCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function type(): string {
        return self::ZSET;
    }
}
