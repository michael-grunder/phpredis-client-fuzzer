<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ListMoveManyCommand;

class blmovem extends ListMoveManyCommand {
    protected function blocking(): bool {
        return true;
    }
}
