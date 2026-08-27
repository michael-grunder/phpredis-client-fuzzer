<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class ListMPopCommand extends Command implements FuzzInterface,
                                                          FuzzRawInterface
{
    private const FROM = ['LEFT' => true, 'RIGHT' => true];

    abstract protected function hasTimeout(): bool;

    public function flags(): int {
        return self::WRITE | ($this->hasTimeout() ? self::BLOCKING : 0) | self::INVALIDATING;
    }

    public function type(): string {
        return self::LIST;

    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [];

        if ($this->hasTimeout())
            $args[] = $config->getRandomTimeout();

        $args[] = $config->getRandomKeys($this->type());
        $args[] = array_rand(self::FROM);

        if (rand(1, 2) == 1)
            $args[] = rand(1, $config->getMembers());

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [];

        if ($this->hasTimeout())
            $args[] = $config->getRandomTimeout();

        $keys = $config->getRandomKeys($this->type());
        $args[] = count($keys);
        foreach ($keys as $key)
            $args[] = $key;

        $args[] = rand() & 1 ? 'LEFT' : 'RIGHT';

        if (rand() & 1) {
            $args[] = 'COUNT';
            $args[] = rand(1, $config->getMembers());
        }

        return $this->execRaw($client, ...$args);
    }
}
