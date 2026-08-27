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

class zdiff extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE;
    }

    /**
     * @return array<string, bool>|null
     */
    protected function randomOptions(Redis|RedisCluster|Relay|Cluster $client): ?array {
        if (rand(1, 2) == 1)
            return ['WITHSCORES' => rand(1, 2) === 1];

        return self::isRelay($client) ? null : [];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKeys($this->type()),
            $this->randomOptions($client)
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = $config->getRandomKeys($this->type());
        if (rand() & 1)
            $args[] = 'WITHSCORES';

        return $this->execRaw($client, ...$args);
    }
}
