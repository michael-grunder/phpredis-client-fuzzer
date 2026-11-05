<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\SetMemberExpiryCommand;

class sexpireat extends SetMemberExpiryCommand {
    protected function expiry(FuzzConfig $config): int {
        return $config->getRandomExpireAt();
    }
}
