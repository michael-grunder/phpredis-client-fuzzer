<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\ProxyInterface;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\Command;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class lset extends Command implements ProxyInterface, FuzzInterface,
                                      FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::LIST;
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

        $len = $this->cmd($server, 'lLen', $key);
        if ( ! is_int($len) || ! $len)
            return false;

        $val = $this->cmd($server, 'lIndex', $key, rand(0, $len - 1));
        if ( ! $val || is_object($val))
            return false;

        return $this->exec($client, $config->getRandomKey($this->type()),
                           rand(0, $len - 1), $val);
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        if ($fn == 'exec') {
            $value = $config->getRandomValue($client, $this->type());
        } else {
            $value = $config->getRandomString();
        }

        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomIndex(),
            $value
        );
    }
}
