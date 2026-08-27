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

class zmpop extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKeys($this->type()),
            rand() & 1 ? 'MIN' : 'MAX',
        ];

        if (rand() & 1)
            $args[] = rand(1, $config->getMembers());

        return $this->exec($client, ...$args);
    }

    //zmpop numkeys key [key ...] MIN|MAX [COUNT count]
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $rand = rand();
        $keys = $config->getRandomKeys($this->type());
        $args = [count($keys), ...$keys, $rand & 1 ? 'MIN' : 'MAX'];
        if ($rand & 2) {
            $args[] = 'COUNT';
            $args[] = rand(1, $config->getMembers());
        }

        return $this->execRaw($client, ...$args);
    }
}
