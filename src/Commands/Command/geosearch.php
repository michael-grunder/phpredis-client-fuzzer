<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\GeoSearchCommand;

class geosearch extends GeoSearchCommand {
    public function flags(): int {
        return self::READ;
    }
}
