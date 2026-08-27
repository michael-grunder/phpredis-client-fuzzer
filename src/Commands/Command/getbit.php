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

class getbit extends Command implements FuzzInterface {
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            rand(0, $config->getMaxLen() * 8),
        );
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->fuzzGeneric($client, $config, 'exec');
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->fuzzGeneric($client, $config, 'execRaw');
    }
}
