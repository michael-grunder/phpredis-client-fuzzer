<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ListMPopCommand;

class blmpop extends ListMPopCommand {
    protected function hasTimeout(): bool {
        return true;
    }
}
