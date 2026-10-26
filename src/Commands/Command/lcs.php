<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\ProxyInterface;
use Mgrunder\RedisClientTester\Commands\KeySample;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class lcs extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $options = null;

        $args = [
            $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
        ];

        $rng = rand();
        if ($rng & 0x1)
            $options['MINMATCHLEN'] = $rng % 10;
        if ($rng & 0x2)
            $options['WITHMATCHLEN'] = $rng & 0x4;
        if ($rng & 0x8)
            $options[] = 'LEN';
        else if ($rng & 0x10)
            $options[] = 'IDX';

        if ($options !== null)
            $args[] = $options;

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args[] = $config->getRandomKey($this->type());
        $args[] = $config->getRandomKey($this->type());

        $rand = rand();

        if ($rand & 1)
            $args[] = 'LEN';
        else if ($rand & 2)
            $args[] = 'IDX';

        if ($rand & 4) {
            $args[] = 'MINMATCHLEN';
            $args[] = $rand % 10;
        }

        if ($rand & 8)
            $args[] = 'WITHMATCHLEN';

        return $this->execRaw($client, ...$args);
    }
}
