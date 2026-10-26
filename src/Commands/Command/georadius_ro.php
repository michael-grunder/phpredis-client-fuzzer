<?php

namespace Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\GeoRadiusCommand;

class georadius_ro extends GeoRadiusCommand {
    public function flags(): int {
        return self::READ;
    }
}
