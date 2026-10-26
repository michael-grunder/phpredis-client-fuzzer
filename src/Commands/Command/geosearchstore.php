<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\GeoSearchCommand;

class geosearchstore extends GeoSearchCommand {
    public function flags(): int {
        return self::WRITE;
    }
}
