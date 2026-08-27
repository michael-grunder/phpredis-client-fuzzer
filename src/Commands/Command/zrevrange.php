<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zrevrange extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $options = match(rand(1, 5)) {
            1 => null,
            2 => true,
            3 => ['withscores'],
            4 => ['withscores' => false],
            5 => ['withscores' => true],
        };

        [$start, $end] = $config->randomRange();

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $start, $end, $options
        );
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$key, [$start, $end]] = [
            $config->getRandomKey($this->type()), $config->randomRange()
        ];

        $withscores = rand() & 1 ? ['WITHSCORES'] : [];

        return $this->execRaw($client, $key, $start, $end, ...$withscores);
    }
}
