<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\MixedCommand;

class _pack extends MixedCommand {
    public function flags(): int {
        return self::LOCAL;
    }

    public function type(): string {
        return self::NONE;
    }
}
