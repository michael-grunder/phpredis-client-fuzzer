<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\XRangeCommand;
use Mgrunder\RedisClientTester\Data\Events;

class xrevrange extends XRangeCommand {
    protected function randomIdRange(): array {
        return array_reverse(Events::instance()->randomIdRange());
    }
}
