<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\PingCommand;

class echo_cmd extends PingCommand {
    protected function argOptional(): bool {
        return false;
    }

    public function name(): string {
        return 'echo';
    }
}
