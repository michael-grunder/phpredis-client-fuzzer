<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class FlushCommand extends Command implements FuzzInterface, FuzzRawInterface {
    use Traits\FuzzGeneric;
    public function flags(): int {
        return self::WRITE | self::FLUSH | self::INVALIDATING;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        if (($client instanceOf Redis) || ($client instanceOf Relay))
            return $this->$fn($client);

        return $this->$fn($client, $config->getRandomKey(self::ANY));
    }
}
