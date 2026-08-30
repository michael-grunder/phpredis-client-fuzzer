<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

interface StatefulScenario
{
    public function name(): string;

    /**
     * @param Redis|RedisCluster|Relay|Cluster $client
     */
    public function run(
        Redis|RedisCluster|Relay|Cluster $client,
        int $seed,
        int $clientIndex,
    ): StatefulOutcome;
}
