<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\KeyMemCommand;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\ProxyInterface;

use Relay\Relay;
use Redis;

class zscore extends KeyMemCommand implements ProxyInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ | self::CACHED | self::COMMON;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return null;

        $member = $config->getRandomMember($this->type());
        if (rand(0, 1) === 1) {
            $existing = $this->cmd($server, 'zrandmember', $key);
            if (is_string($existing))
                $member = $existing;
        }

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $member,
        );
    }
}
