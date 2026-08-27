<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class blmove extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    private const POSITION = [Redis::LEFT => true, Redis::RIGHT => true];

    public function type(): string {
        return self::LIST;
    }

    public function flags(): int {
        return self::WRITE | self::BLOCKING | self::INVALIDATING;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
            array_rand(self::POSITION),
            array_rand(self::POSITION),
            $config->getRandomTimeout(),
        );
    }
}
