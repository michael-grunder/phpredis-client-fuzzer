<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\GeoRadiusByMemberCommand;

class georadiusbymember_ro extends GeoRadiusByMemberCommand {
    public function flags(): int {
        return self::READ;
    }
}
