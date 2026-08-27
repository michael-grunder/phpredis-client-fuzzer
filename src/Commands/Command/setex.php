<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\SetExCommand;


class setex extends SetExCommand {
    public function getTimeout(int $seconds): int {
        return $seconds;
    }
}
