<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SetCardinalityCommand;

class sunioncard extends SetCardinalityCommand {
    protected function supportsApprox(): bool {
        return true;
    }
}
