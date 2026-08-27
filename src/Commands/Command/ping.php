<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\PingCommand;

class ping extends PingCommand {
    protected function argOptional(): bool {
        return true;
    }
}
