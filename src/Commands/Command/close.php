<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\EmptyLocalCommand;

class close extends EmptyLocalCommand {
    public function flags(): int {
        return self::CONNECTION;
    }
}
