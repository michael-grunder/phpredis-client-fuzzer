<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FCallCommand;

class fcall extends FCallCommand {
    public function flags(): int {
        return self::READ | self::WRITE;
    }
}
