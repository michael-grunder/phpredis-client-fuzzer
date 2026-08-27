<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class ListPushCommand extends Command implements ProxyInterface,
                                                          FuzzInterface,
                                                          FuzzRawInterface
{
    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::LIST;
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

        $members = $this->cmd($client, 'lRange', $key, ...$this->randomRange($len));
        if ( ! is_array($members) || ! $members)
            return false;

        return $this->exec(
            $client, $config->getRandomKey($this->type()), ...$members
        );
    }

    protected function maxElements(Redis|RedisCluster|Relay|Cluster $client): int {
        return -1;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey($this->type());

        $elements = $config->getRandomValues($client, $this->type());
        if (($max = $this->maxElements($client)) > 0)
            $elements = array_slice($elements, 0, $max);

        return $this->exec($client, $key, ...$elements);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $elements = $config->getRandomValues($client, $this->type());

        return $this->execRaw($client, $key, ...$elements);
    }
}
