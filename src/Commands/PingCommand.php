<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class PingCommand extends Command implements FuzzInterface,
                                                      FuzzRawInterface
{
    public function type(): string {
        return self::NONE;
    }

    public function flags(): int {
        return self::READ;
    }

    abstract protected function argOptional(): bool;

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                               FuzzConfig $config): mixed
    {
        $args = $this->isCluster($client) ? [$config->getRandomKey(self::ANY)] : [];

        if ( ! $this->argOptional() || rand(1, 2) == 1) {
            $args[] = $config->getRandomString();
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = $this->isCluster($client) ? [$config->getRandomKey(self::ANY)] : [];

        if ( ! $this->argOptional() || rand(1, 2) == 1) {
            $args[] = $config->getRandomString();
        }

        return $this->execRaw($client, ...$args);
    }
}
