<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\HashFieldExpiryCommand;

class hpexpire extends HashFieldExpiryCommand {
    protected function expiry(FuzzConfig $config): int {
        return $config->getRandomExpire(true);
    }
}
