<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\HIncrByCommand;

class hincrbyfloat extends HIncrByCommand {
    protected function getRandomMember(FuzzConfig $config): string {
        return $config->getRandomMember($this->type(), ['float']);
    }

    protected function getRandomValue(FuzzConfig $config): int|float {
        return $config->getRandomFloat();
    }
}
