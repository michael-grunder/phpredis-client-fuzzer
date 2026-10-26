<?php

namespace Mgrunder\RedisClientTester\Commands;


use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class ZRankCommand extends Command implements FuzzInterface,
                                                       FuzzRawInterface
{
    use Traits\FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            $config->getRandomMember($this->type()),
        ];
        if ($fn === 'execRaw') {
            if (rand(0, 1)) {
                $args[] = 'WITHSCORE';
            }
        } else {
            // Some PhpRedis versions expose only the two-argument API.
            $method = new \ReflectionMethod($client, $this->name());
            if ($method->getNumberOfParameters() >= 3 || $method->isVariadic()) {
                $withScore = rand(0, 2);
                if ($withScore !== 0) {
                    $args[] = $withScore === 2;
                }
            }
        }

        return $this->$fn($client, ...$args);
    }
}
