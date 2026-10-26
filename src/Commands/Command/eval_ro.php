<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\EvalCommand;

class eval_ro extends EvalCommand {
    protected function processScript(string $script): string {
        return $script;
    }

    public function flags(): int {
        return self::READ;
    }
}
