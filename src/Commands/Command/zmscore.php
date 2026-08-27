<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeyMemsCommand;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zmscore extends KeyMemsCommand {
    public function flags(): int {
        return self::READ;
    }

    public function type(): string {
        return self::ZSET;
    }

    /**
     * @return ?array<mixed>
     */
    protected function getMembers(Redis|Relay $server, string $key): ?array {
        $len = $this->cmd($server, 'zcard', $key);
        if ( ! $len || ! is_int($len))
            return null;

        $result = $this->cmd($server, 'zrandmember', ['count' => rand(1, $len)]);
        if ( ! is_array($result))
            return null;

        return $result;
    }
}
