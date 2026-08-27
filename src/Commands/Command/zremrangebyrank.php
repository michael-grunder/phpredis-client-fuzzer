<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ZRemRangeByCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

class zremrangebyrank extends ZRemRangeByCommand {
    public function getRange(FuzzConfig $config): array {
        return $config->randomRange();
    }
}
