<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FCallCommand;

class fcall_ro extends FCallCommand {
    public function flags(): int {
        return self::READ;
    }
}
