<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ZRemRangeByCommand;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

class zremrangebyrank extends ZRemRangeByCommand {
    public function getRange(FuzzConfig $config): array {
        return $config->randomRange();
    }
}
