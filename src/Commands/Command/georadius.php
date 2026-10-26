<?php

namespace Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\GeoRadiusCommand;

class georadius extends GeoRadiusCommand {
    public function flags(): int {
        return self::READ | self::WRITE;
    }
}
