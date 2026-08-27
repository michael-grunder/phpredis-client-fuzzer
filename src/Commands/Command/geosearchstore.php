<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\GeoSearchCommand;

class geosearchstore extends GeoSearchCommand {
    public function flags(): int {
        return self::WRITE;
    }
}
