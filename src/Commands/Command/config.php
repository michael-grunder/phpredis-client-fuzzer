<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class config extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    private const IDENTIFIERS = [
        'dynamic-hz',
        'maxmemory',
        'appendonly',
        'maxmemory-policy',
        'min-replicas-to-write',
        'shutdown-on-sigint',
        'databases',
        'hz',
        'logfile',
        'port',
        'loglevel',
    ];

    public function flags(): int {
        return self::READ | self::WRITE | self::ADMIN;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $args = [];

        if ($fn !== 'execRaw' &&
            (($client instanceOf RedisCluster) || ($client instanceOf Cluster)))
            $args[] = $config->getRandomKey(self::ANY);

        if (rand() & 1) {
            $id = self::IDENTIFIERS[array_rand(self::IDENTIFIERS)];
        } else {
            $id = '*' . chr(rand(97, 122)) . '*';
        }

        $args[] = 'GET';
        $args[] = $id;

        return $this->$fn($client, ...$args);
    }
}
