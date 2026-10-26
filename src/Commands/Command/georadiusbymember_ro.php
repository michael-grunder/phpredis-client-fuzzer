<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\GeoRadiusByMemberCommand;

class georadiusbymember_ro extends GeoRadiusByMemberCommand {
    public function flags(): int {
        return self::READ;
    }
}
