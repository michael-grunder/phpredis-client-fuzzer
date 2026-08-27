<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\PingCommand;

class echo_cmd extends PingCommand {
    protected function argOptional(): bool {
        return false;
    }

    public function name(): string {
        return 'echo';
    }
}
