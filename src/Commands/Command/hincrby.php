<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\HIncrByCommand;

class hincrby extends HIncrByCommand {
    protected function getRandomMember(FuzzConfig $config): string {
        return $config->getRandomMember($this->type(), ['int']);
    }

    protected function getRandomValue(FuzzConfig $config): int|float {
        return $config->getRandomInt();
    }
}
