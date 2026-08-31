<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xautoclaim extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey($this->type());

        $extra = match(rand(0, 2)) {
            0 => [],
            1 => [rand(1, $config->randomMemberCount())],
            2 => [rand(1, $config->randomMemberCount()), rand() & 1],
        };

        return $this->exec(
            $client,
            $key,
            'fuzzer',
            $config->getConsumer(),
            rand(0, $config->getRandomTimeoutMs()),
            Events::instance()->randomId(),
            ...$extra
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $args = [
            $key,
            'fuzzer',
            $config->getConsumer(),
            rand(0, $config->getRandomTimeoutMs()),
            Events::instance()->randomId(),
        ];

        $rng = rand();
        if ($rng & 1) {
            array_push($args, 'COUNT', rand(1, $config->randomMemberCount()));
            if ($rng & 2)
                $args[] = 'JUSTID';
        }

        return $this->execRaw($client, ...$args);
    }
}

// public function xautoclaim(
//     string $key,
//     string $group,
//     string $consumer,
//     int $min_idle,
//     string $start,
//     int $count = -1,
//     bool $justid = false
// ): Redis|bool|array;
