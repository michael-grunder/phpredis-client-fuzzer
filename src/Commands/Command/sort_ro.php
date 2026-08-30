<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\SortCommand;

class sort_ro extends SortCommand {
    protected function canStore(): bool {
        return false;
    }
}
