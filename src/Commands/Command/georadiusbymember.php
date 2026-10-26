<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\GeoRadiusByMemberCommand;

class georadiusbymember extends GeoRadiusByMemberCommand {
    public function flags(): int {
        return self::READ | self::WRITE | self::INVALIDATING;
    }
}
