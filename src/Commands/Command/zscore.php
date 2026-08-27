<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeyMemCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;

use Relay\Relay;
use Redis;

class zscore extends KeyMemCommand implements ProxyInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
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
