<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ListMPopCommand;

class lmpop extends ListMPopCommand {
    protected function hasTimeout(): bool {
        return false;
    }
}
