<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

enum ClientType: string
{
    case Redis = 'redis';
    case RedisCluster = 'redis-cluster';
    case Relay = 'relay';
    case RelayCluster = 'relay-cluster';

    public function className(): string
    {
        return match ($this) {
            self::Redis => \Redis::class,
            self::RedisCluster => \RedisCluster::class,
            self::Relay => \Relay\Relay::class,
            self::RelayCluster => \Relay\Cluster::class,
        };
    }

    public function isCluster(): bool
    {
        return $this === self::RedisCluster || $this === self::RelayCluster;
    }
}
