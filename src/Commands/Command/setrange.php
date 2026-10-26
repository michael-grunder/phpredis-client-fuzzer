<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ProxyInterface;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\Command;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class setrange extends Command implements ProxyInterface, FuzzInterface,
                                          FuzzRawInterface
{
    use FuzzGeneric;

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::STRING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return null;

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            rand(0, $config->getMaxLen()),
            $this->cmd($server, 'get', $key)
        );
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        if ($fn == 'exec' && self::isRelay($client)) {
            $value = $config->getRandomValue($client, $this->type());
        } else {
            $value = $config->getRandomString();
        }

        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            rand(0, $config->getMaxLen()),
            $value,
        );
    }
}
