<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeyRangeCommand;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class lrange extends KeyRangeCommand {
    public function len(Redis|Relay $client, string $key): ?int {
        $len = $this->cmd($client, 'llen', $key);
        if ( ! $len || ! is_int($len))
            return null;

        return $len;
    }

    public function type(): string {
        return self::LIST;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }
}
