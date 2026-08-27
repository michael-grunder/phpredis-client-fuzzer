<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class SetExCommand extends Command implements ProxyInterface,
                                                       FuzzInterface,
                                                       FuzzRawInterface

{
    use Traits\FuzzGeneric;

    abstract protected function getTimeout(int $seconds): int;

    public function type(): string {
        return self::STRING;
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

        $val = $this->cmd($server, 'get', $key);
        if ( ! $val || is_object($val))
            return false;

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $this->getTimeout(rand(1, 60)),
            $val
        );
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $this->getTimeout(rand(1, 60)),
            $config->getRandomValue($client, $this->type())
        );
    }
}
