<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SortCommand;

class sort extends SortCommand {
    protected function canStore(): bool {
        return true;
    }
}
