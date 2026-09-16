<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ZRangeByScoreCommand;

class zrangebyscore extends ZRangeByScoreCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    protected function reverse(): bool {
        return false;
    }
}
