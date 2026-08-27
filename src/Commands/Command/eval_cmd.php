<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\EvalCommand;

class eval_cmd extends EvalCommand {
    public function name(): string {
        return 'eval';
    }

    public function flags(): int {
        return self::WRITE;
    }

    protected function processScript(string $script): string {
        return $script;
    }
}
