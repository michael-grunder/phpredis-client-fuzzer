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
    public function __construct(
        private readonly ClientInvoker $clientInvoker = new StrictClientInvoker(),
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
        foreach (StatefulScenarioRegistry::select(
            $scenarioNames,
            $seed,
            $this->clientInvoker,
        ) as $scenario) {
            foreach ($clients as $clientIndex => $client) {
                $outcomes[] = $scenario->run($client, $seed, $clientIndex);
            }
        }

        return $outcomes;
    }
}
