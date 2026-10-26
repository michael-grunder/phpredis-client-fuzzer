<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\SetCardinalityCommand;

class sdiffcard extends SetCardinalityCommand {
    protected function supportsApprox(): bool {
        return false;
    }
}
