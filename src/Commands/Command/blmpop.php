<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListMPopCommand;

class blmpop extends ListMPopCommand {
    protected function hasTimeout(): bool {
        return true;
    }
}
