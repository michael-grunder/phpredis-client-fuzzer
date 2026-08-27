<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ZRangeByScoreCommand;

class zrangebyscore extends ZRangeByScoreCommand {
    protected function reverse(): bool {
        return false;
    }
}
