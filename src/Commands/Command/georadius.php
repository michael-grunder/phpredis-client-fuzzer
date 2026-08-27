<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\GeoRadiusCommand;

class georadius extends GeoRadiusCommand {
    public function flags(): int {
        return self::READ | self::WRITE;
    }
}
