<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\EvalCommand;

class eval_ro extends EvalCommand {
    protected function processScript(string $script): string {
        return $script;
    }

    public function flags(): int {
        return self::READ;
    }
}
