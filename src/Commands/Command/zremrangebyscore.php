<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ZRemRangeByCommand;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\ZSetRange;


class zremrangebyscore extends ZRemRangeByCommand  {
    public function getRange(FuzzConfig $config): array {
        return ZSetRange::scores($config);
    }
}
