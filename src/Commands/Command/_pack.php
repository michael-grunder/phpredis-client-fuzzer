<?php

namespace Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\MixedCommand;

class _pack extends MixedCommand {
    public function flags(): int {
        return self::LOCAL;
    }

    public function type(): string {
        return self::NONE;
    }
}
