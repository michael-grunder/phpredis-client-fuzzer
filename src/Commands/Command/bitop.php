<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class bitop extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    private const OPS = ['AND' => 1, 'OR' => 1, 'XOR' => 1, 'NOT' => 1];

    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $op = array_rand(self::OPS);

        $other_keys = ($op != 'NOT' && rand() & 1) ? $config->getRandomKeys($this->type()) : [];

        return $this->$fn(
            $client,
            $op,
            $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
            ...$other_keys,
        );
    }
}
