<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zrandmember extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $nulls = self::isRelay($client) ? [null, []] : [[]];

        $options = match(rand(1, 4)) {
            1 => $nulls[array_rand($nulls)],
            2 => ['WITHSCORES'],
            3 => ['COUNT' => rand(1, $config->getMembers())],
            4 => ['WITHSCORES', 'COUNT' => rand(1, $config->getMembers())],
        };

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $options,
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKey($this->type())];
        $rand = rand();

        if ($rand & 1) {
            $args[] = rand(1, $config->getMembers());
            if ($rand & 2) {
                $args[] = 'WITHSCORES';
            }
        }

        return $this->execRaw($client, ...$args);
    }
}
