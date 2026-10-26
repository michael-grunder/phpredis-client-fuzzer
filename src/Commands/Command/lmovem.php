<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ListMoveManyCommand;

class lmovem extends ListMoveManyCommand {
    protected function blocking(): bool {
        return false;
    }
}
