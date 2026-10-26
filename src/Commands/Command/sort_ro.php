<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SortCommand;

class sort_ro extends SortCommand {
    protected function canStore(): bool {
        return false;
    }
}
