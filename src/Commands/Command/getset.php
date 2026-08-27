<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class getset extends Command implements FuzzInterface, FuzzRawInterface,
                                        ProxyInterface
{
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $keys = $keys->consumeNKeys($this->type(), 2);
        if ( ! $keys)
            return false;

        $val = $this->cmd($server, 'get', $keys[0]);
        if ( is_object($val) || ! $val)
            return false;

        return $this->exec($client, $config->getRandomKey($this->type()), $val);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomValue($client, $this->type())
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomString()
        );
    }
}
