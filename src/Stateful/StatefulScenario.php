<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

interface StatefulScenario
{
    public function name(): string;

    /**
     * @param Redis|RedisCluster|Relay|Cluster $client
     * @param ScenarioPeers|null $peers Optional source of a second connection;
     *        scenarios that need one report `skipped` without it.
     */
    public function run(
        Redis|RedisCluster|Relay|Cluster $client,
        int $seed,
        int $clientIndex,
        ?ScenarioPeers $peers = null,
    ): StatefulOutcome;
}
