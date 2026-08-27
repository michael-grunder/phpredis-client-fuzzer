<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ZRangeByScoreCommand;

class zrevrangebyscore extends ZRangeByScoreCommand {
    protected function reverse(): bool {
        return true;
    }
}
