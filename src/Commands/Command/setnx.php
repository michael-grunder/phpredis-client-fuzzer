<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyValCommand;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class setnx extends KeyValCommand {
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::WRITE;
    }

    protected function getValue(Redis|Relay $server, string $key): mixed {
        if (rand(0, 1) == 0)
            return sprintf('value:%d', mt_rand());

        return $this->cmd($server, 'get', $key);
    }
}
