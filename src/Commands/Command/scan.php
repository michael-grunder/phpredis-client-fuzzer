<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ScanCommand;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

class scan extends ScanCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }

    public function type(): string {
        return self::ANY;
    }

    protected function initArgs(FuzzConfig $config): void {
        $rng = rand();

        if ($rng & 1)
            $this->type = $this->randomType();

        if ($rng & 2) {
            $id = $rng % $config->getCmdMaxKeys();
            $this->pattern = "*$id*";
        }
    }
}
