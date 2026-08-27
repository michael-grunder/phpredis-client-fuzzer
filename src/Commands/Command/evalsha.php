<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\EvalCommand;

class evalsha extends EvalCommand {
    protected function processScript(string $script): string {
        return sha1($script);
    }

    public function flags(): int {
        return self::WRITE;
    }
}
