<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\HashFieldExpiryCommand;

class hpexpireat extends HashFieldExpiryCommand {
    protected function expiry(FuzzConfig $config): int {
        return $config->getRandomExpireAt(true);
    }
}
