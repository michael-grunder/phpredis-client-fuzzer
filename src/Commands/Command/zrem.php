<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\KeyMemsCommand;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zrem extends KeyMemsCommand {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING | self::COMMON;
    }

    /**
     * @return ?array<mixed>
     */
    protected function getMembers(Redis|Relay $server, string $key): ?array {
        $len = $this->cmd($server, 'zCard', $key);
        if ( ! is_int($len) || ! $len)
            return null;

        $members = $this->cmd($server, 'zRange', $key, ...$this->randomRange($len));
        if ( ! is_array($members) || ! $members)
            return null;

        return $members;
    }
}
