<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ScanCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

class scan extends ScanCommand {
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
