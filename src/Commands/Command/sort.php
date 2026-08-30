<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\SortCommand;

class sort extends SortCommand {
    protected function canStore(): bool {
        return true;
    }
}
