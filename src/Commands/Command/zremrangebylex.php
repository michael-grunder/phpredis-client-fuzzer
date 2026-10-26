<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ZRemRangeByCommand;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\ZSetRange;

class zremrangebylex extends ZRemRangeByCommand {
    public function getRange(FuzzConfig $config): array {
        return ZSetRange::lex($config);
    }
}
