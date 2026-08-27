<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ZRemRangeByCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;


class zremrangebyscore extends ZRemRangeByCommand  {
    public function getRange(FuzzConfig $config): array {
        return [
            $config->getRandomFloat(),
            $config->getRandomFloat(),
        ];
    }
}
