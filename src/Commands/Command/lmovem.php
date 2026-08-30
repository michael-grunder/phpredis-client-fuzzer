<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListMoveManyCommand;

class lmovem extends ListMoveManyCommand {
    protected function blocking(): bool {
        return false;
    }
}
