<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zdiff extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
    }

    /**
     * @return array<string, bool>|null
     */
    protected function randomOptions(Redis|RedisCluster|Relay|Cluster $client): ?array {
        if (rand(1, 2) == 1)
            return ['WITHSCORES' => rand(1, 2) === 1];

        return rand(0, 1) ? null : [];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKeys($this->type())];
        if (rand(0, 1)) {
            $args[] = $this->randomOptions($client);
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());
        $args = [count($keys), ...$keys];
        if (rand() & 1)
            $args[] = 'WITHSCORES';

        return $this->execRaw($client, ...$args);
    }
}
