<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\GeoRadiusByMemberCommand;

class georadiusbymember extends GeoRadiusByMemberCommand {
    public function flags(): int {
        return self::READ | self::WRITE | self::INVALIDATING;
    }
}
