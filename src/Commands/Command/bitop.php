<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class bitop extends Command implements FuzzInterface {
    private const OPS = ['AND' => 1, 'OR' => 1, 'XOR' => 1, 'NOT' => 1];

    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $op = array_rand(self::OPS);

        $other_keys = ($op != 'NOT' && rand() & 1) ? $config->getRandomKeys($this->type()) : [];

        return $this->exec(
            $client,
            $op,
            $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
            ...$other_keys,
        );
    }
}
