<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\EvalCommand;

class evalsha extends EvalCommand {
    protected function processScript(string $script): string {
        return sha1($script);
    }

    public function flags(): int {
        return self::WRITE;
    }
}
