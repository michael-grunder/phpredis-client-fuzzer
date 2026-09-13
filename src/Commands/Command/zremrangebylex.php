<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ZRemRangeByCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\ZSetRange;

class zremrangebylex extends ZRemRangeByCommand {
    public function getRange(FuzzConfig $config): array {
        return ZSetRange::lex($config);
    }
}
