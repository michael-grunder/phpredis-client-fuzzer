<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;
use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class bitcount extends Command implements FuzzInterface,
                                          FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    private function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                 FuzzConfig $config, string $fn): mixed
    {
        $args = [$config->getRandomKey($this->type())];

        $rng = rand(0, 4);

        if ($rng--)
            $args[] = rand(-1 * $config->getMaxLen(), $config->getMaxLen());
        if ($rng--)
            $args[] = rand(-1 * $config->getMaxLen(), $config->getMaxLen());
        if ($rng--) {
            $args[] = stripos($fn, 'raw') ? ($rng > 0 ? 'BYTE' : 'BIT') : ($rng > 0);
        }

        return $this->$fn($client, ...$args);
    }
}
