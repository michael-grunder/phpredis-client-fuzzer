<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class migrate extends Command implements ProxyInterface {
    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::WRITE | self::BLOCKING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $type = $this->randomRedisType();
        $rng  = rand();

        $key = ($rng & 0x1) ? $config->getRandomKey($type) : $config->getRandomKeys($type);
        if ( ! $key)
            return false;

        /* Optionally specify COPY and REPLACE */
        $options = [];
        if ($rng & 0x2)
            $options[] = !!($rng & 0x4);
        if ($rng & 0x8)
            $options[] = !!($rng & 0x10);

        return $this->exec(
            $client,
            $server->getHost(),
            $server->getPort(),
            $key,
            $config->getRandomDB(),
            $config->getRandomTimeoutMs(),
            ...$options,
        );
    }
}
