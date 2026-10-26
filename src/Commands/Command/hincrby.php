<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\HIncrByCommand;

class hincrby extends HIncrByCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    protected function getRandomMember(FuzzConfig $config): string {
        return $config->getRandomMember($this->type(), ['int']);
    }

    protected function getRandomValue(FuzzConfig $config): int|float {
        return $config->getRandomInt();
    }
}
