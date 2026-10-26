<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\HashFieldExpiryCommand;

class hpexpireat extends HashFieldExpiryCommand {
    protected function expiry(FuzzConfig $config): int {
        return $config->getRandomExpireAt(true);
    }
}
