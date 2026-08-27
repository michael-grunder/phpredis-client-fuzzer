<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\GeoSearchCommand;

class geosearch extends GeoSearchCommand {
    public function flags(): int {
        return self::READ;
    }
}
