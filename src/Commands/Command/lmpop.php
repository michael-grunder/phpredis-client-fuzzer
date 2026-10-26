<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ListMPopCommand;

class lmpop extends ListMPopCommand {
    protected function hasTimeout(): bool {
        return false;
    }
}
