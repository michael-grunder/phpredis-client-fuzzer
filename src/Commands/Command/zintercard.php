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

class zintercard extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKeys($this->type())];

        if (rand() & 1)
            $args[] = rand(0, $config->getMembers());

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());
        $args = [count($keys), ...$keys];

        if (rand() & 1) {
            $args[] = 'LIMIT';
            $args[] = rand(0, $config->getMembers());
            $args[] = rand(0, $config->getMembers());
        }

        return $this->execRaw($client, ...$args);
    }
}
