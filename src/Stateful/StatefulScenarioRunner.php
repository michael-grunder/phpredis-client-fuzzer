<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class StatefulScenarioRunner
{
    /**
     * @param ScenarioPeers|null $peers Optional source of second connections.
     *        Scenarios that need one to prove watch semantics report `skipped`
     *        when it is absent; every other scenario ignores it.
     */
    public function __construct(
        private readonly ClientInvoker $clientInvoker = new StrictClientInvoker(),
        private readonly ?ScenarioPeers $peers = null,
    ) {
    }

    /**
     * @param list<Redis|RedisCluster|Relay|Cluster> $clients
     * @param list<string> $scenarioNames
     * @return list<StatefulOutcome>
     */
    public function run(array $clients, array $scenarioNames, int $seed): array
    {
        if ($scenarioNames === []) {
            return [];
        }

        $outcomes = [];
        try {
            foreach (StatefulScenarioRegistry::select(
                $scenarioNames,
                $seed,
                $this->clientInvoker,
            ) as $scenario) {
                foreach ($clients as $clientIndex => $client) {
                    $outcomes[] = $scenario->run($client, $seed, $clientIndex, $this->peers);
                }
            }
        } finally {
            /* Peers exist for the scenarios only; the random command stream
               must not keep extra connections open. */
            $this->peers?->release();
        }

        return $outcomes;
    }
}
