<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\HIncrByCommand;

class hincrbyfloat extends HIncrByCommand {
    protected function getRandomMember(FuzzConfig $config): string {
        return $config->getRandomMember($this->type(), ['float']);
    }

    protected function getRandomValue(FuzzConfig $config): int|float {
        return $config->getRandomFloat();
    }
}
