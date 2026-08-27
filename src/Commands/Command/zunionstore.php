<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\MZSetCommand;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zunionstore extends MZSetCommand {
    /**
     * @param string[] $keys
     * @return array<mixed>
     */
    protected function randomOptions(Redis|RedisCluster|Relay|Cluster $client,
                                     array $keys): array|string|null
    {
        $result = [];

        if (self::isRelay($client))
            return parent::randomOptions($client, $keys);

        if (rand(1, 2) == 1)
            return null;

        return array_rand(self::AGGREGATE);
    }

    public function flags(): int {
        return self::WRITE;
    }
}
