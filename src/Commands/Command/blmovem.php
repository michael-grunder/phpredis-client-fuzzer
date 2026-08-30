<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListMoveManyCommand;

class blmovem extends ListMoveManyCommand {
    protected function blocking(): bool {
        return true;
    }
}
