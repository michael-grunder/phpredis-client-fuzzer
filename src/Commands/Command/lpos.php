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

class lpos extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::LIST;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            $config->getRandomValue($client, $this->type()),
        ];

        $rng = rand();

        if ($rng & 1) {
            $args[] = 'COUNT';
            $args[] = rand(0, $config->getMembers());
        }

        if ($rng & 2) {
            $args[] = 'RANK';
            $args[] = 1 | rand(-1 * $config->getMembers(), $config->getMembers());
        }

        if ($rng & 4) {
            $args[] = 'MAXLEN';
            $args[] = rand(0, $config->getMembers());
        }

        return $this->execRaw($client, ...$args);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            $config->getRandomValue($client, $this->type()),
        ];

        $rng = rand();

        $options = null;

        if ($rng & 1)
            $options['COUNT'] = rand(0, $config->getMembers());
        if ($rng & 2)
            $options['RANK'] = 1 | rand(-1 * $config->getMembers(), $config->getMembers());
        if ($rng & 4)
            $options['MAXLEN'] = rand(0, $config->getMembers());

        if ($options !== null)
            $args[] = $options;

        return $this->exec($client, ...$args);
    }
}
