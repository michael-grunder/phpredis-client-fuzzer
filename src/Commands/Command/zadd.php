<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zadd extends Command implements ProxyInterface, FuzzInterface,
                                      FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return false;

        $len = $this->cmd($server, 'zCard', $key);
        if ( ! is_int($len) || ! $len)
            return false;

        [$lo, $hi] = $this->randomRange($len);

        $values = $this->cmd($server, 'zRange', $key, $lo, $hi, true);
        if ( ! is_array($values) || ! $values)
            return false;

        $args = [$config->getRandomKey($this->type())];
        foreach ($values as $member => $score) {
            $args[] = $score;
            $args[] = $member;
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());

        $args = [];

        $members = rand(1, $config->getMembers());
        for ($i = 0; $i < $members; $i++) {
            $args[] = $config->getRandomFloat();
            $args[] = $config->getRandomMember($this->type());
        }

        return $this->$fn($client, $key, ...$args);
    }
}
