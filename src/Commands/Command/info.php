<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class info extends Command implements FuzzInterface, FuzzRawInterface
{
    use FuzzGeneric;

    /* Redis info sections */
    private const SECTIONS = [
        'all' => true,
        'default' => true,
        'server' => true,
        'clients' => true,
        'memory' => true,
        'persistence' => true,
        'stats' => true,
        'replication' => true,
        'cpu' => true,
        'commandstats' => true,
        'cluster' => true,
        'keyspace' => true,
        'log' => true,
        'latency' => true,
        'event' => true,
    ];

    public function flags(): int {
        return self::READ;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $sections = array_rand(self::SECTIONS, rand(1, count(self::SECTIONS)));
        if ( ! is_array($sections))
            $sections = [$sections];

        return $this->$fn($client, ...$sections);
    }
}
