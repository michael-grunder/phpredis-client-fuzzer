<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class bzmpop extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::BLOCKING | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomTimeout(),
            $config->getRandomKeys($this->type()),
            rand(1, 2) == 1 ? 'MIN' : 'MAX',
        ];

        if (rand(1, 2) == 1)
            $args[] = rand(1, $config->getMembers());

        return $this->exec($client, ...$args);
    }

    // timeout numkeys key [key ...] MIN|MAX [COUNT]
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args[] = $config->getRandomTimeout();

        $keys   = $config->getRandomKeys($this->type());
        $args[] = count($keys);
        foreach ($keys as $key)
            $args[] = $key;

        $args[] = rand() & 1 ? 'MIN' : 'MAX';
        if (rand() & 1)
            $args[] = rand(1, $config->getMembers());

        return $this->execRaw($client, ...$args);
    }
}
