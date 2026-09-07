<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\LocalCommand;

class crash extends LocalCommand {

    /* CRASH is the only gate this command needs.  It does not mutate local
       client state the way the other LocalCommand subclasses do, so carrying
       LOCAL as well would mean --include=crash alone could never select it. */
    public function flags(): int {
        return self::CRASH;
    }
}
