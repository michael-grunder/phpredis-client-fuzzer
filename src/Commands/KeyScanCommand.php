<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

abstract class KeyScanCommand extends ScanCommand {
    protected function initArgs(FuzzConfig $config): void {
        $this->key = $config->getRandomKey($this->type());

        $rng = rand();
        if ($rng & 1)
            $this->pattern = '*' . chr(93 + ($rng % 32)) . '*';
    }
}
