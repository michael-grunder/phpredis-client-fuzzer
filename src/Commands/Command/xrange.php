<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\XRangeCommand;
use Mgrunder\RedisClientTester\Data\Events;

class xrange extends XRangeCommand {
    protected function randomIdRange(): array {
        return Events::instance()->randomIdRange();
    }
}
