<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class hrandfield extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::HASH;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $rng = rand();

        $args = [$config->getRandomKey($this->type())];
        if ($rng & 1) {
            $args[] = rand(-1 * $config->getMembers(), $config->getMembers());
            if ($rng & 2)
                $args[] = 'WITHSCORES';
        }

        return $this->execRaw($client, ...$args);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $options = self::isRelay($client) ? null : [];

        $rng = rand();
        if ($rng & 1)
            $options['COUNT'] = rand(-1 * $config->getMembers(), $config->getMembers());
        if ($rng & 2)
            $options['WITHSCORES'] = $rng & 4;

        return $this->exec($client, $config->getRandomKey($this->type()), $options);
    }
}
