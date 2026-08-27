<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\VarKeyCommand;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class del extends VarKeyCommand {
    public function type(): string {
        return Command::ANY;
    }

    public function flags(): int {
        return Command::WRITE | Command::DELETE | self::INVALIDATING;
    }

    protected function takesArray(): bool {
        return false;
    }
}
