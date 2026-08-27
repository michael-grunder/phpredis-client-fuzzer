<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class KeyValCommand extends Command
               implements ProxyInterface, FuzzInterface, FuzzRawInterface
{
    abstract protected function getValue(Redis|Relay $server, string $key): mixed;

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return null;

        $value = $this->getValue($server, $key);
        if ($value === false)
            return false;

        return $this->exec($client, $config->getRandomKey($this->type()), $value);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomValue($client, $this->type()),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomString(),
        );
    }
}
