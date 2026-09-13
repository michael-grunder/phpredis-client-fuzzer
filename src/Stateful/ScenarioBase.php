<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Common shape of the deterministic state-machine scenarios.
 *
 * The base requires an initially atomic client, so an embedded caller's own
 * transaction is never discarded, runs the scenario body, and then restores
 * atomic mode. Cleanup operations are recorded like any other step and a
 * cleanup failure is reported alongside the original failure.
 */
abstract class ScenarioBase implements StatefulScenario
{
    public function __construct(
        protected readonly string $scenario,
        protected readonly ClientInvoker $clientInvoker = new StrictClientInvoker(),
    ) {
    }

    public function name(): string
    {
        return $this->scenario;
    }

    public function run(
        Redis|RedisCluster|Relay|Cluster $client,
        int $seed,
        int $clientIndex,
        ?ScenarioPeers $peers = null,
    ): StatefulOutcome {
        $clientId = $client::class . '#' . $clientIndex;
        foreach ($this->requiredMethods($client) as $method) {
            if (!method_exists($client, $method)) {
                return new StatefulOutcome(
                    scenario: $this->scenario,
                    status: 'skipped',
                    clientId: $clientId,
                    steps: [],
                    postconditions: [],
                    failure: "Client does not expose {$method}()",
                );
            }
        }

        $unsupported = $this->unsupportedReason($client);
        if ($unsupported !== null) {
            return new StatefulOutcome(
                scenario: $this->scenario,
                status: 'skipped',
                clientId: $clientId,
                steps: [],
                postconditions: [],
                failure: $unsupported,
            );
        }

        $run = new ScenarioRun(
            $client,
            $this->clientInvoker,
            $this->scenario,
            $seed,
            $clientIndex,
        );
        $failure = null;
        $skipped = null;
        $ownsState = false;
        try {
            $run->expect('starts-in-atomic-mode', Redis::ATOMIC, $run->mode());
            $ownsState = true;
            $this->execute($run, $peers);
        } catch (ScenarioSkipped $skip) {
            $skipped = $skip->getMessage();
        } catch (\Throwable $throwable) {
            $failure = $throwable::class . ': ' . $throwable->getMessage();
        } finally {
            // Do not discard a transaction that belonged to the embedded caller.
            $run->phase('cleanup');
            if ($ownsState) {
                try {
                    if ($run->mode() !== Redis::ATOMIC) {
                        $run->expect('cleanup-discard-succeeds', true, $run->call('discard'));
                    }
                    if ($run->watchActive()) {
                        $run->expect('cleanup-unwatch-succeeds', true, $run->call('unwatch'));
                    }
                    $run->expect('cleanup-restores-atomic-mode', Redis::ATOMIC, $run->mode());
                } catch (\Throwable $throwable) {
                    $failure = ($failure === null ? '' : $failure . '; ')
                        . 'Cleanup failed: ' . $throwable::class . ': ' . $throwable->getMessage();
                }
            }
        }

        if ($failure === null && $skipped !== null) {
            return new StatefulOutcome(
                scenario: $this->scenario,
                status: 'skipped',
                clientId: $clientId,
                steps: $run->steps(),
                postconditions: $run->postconditions(),
                failure: $skipped,
            );
        }

        return new StatefulOutcome(
            scenario: $this->scenario,
            status: $failure === null ? 'passed' : 'failed',
            clientId: $clientId,
            steps: $run->steps(),
            postconditions: $run->postconditions(),
            failure: $failure,
        );
    }

    /**
     * Methods the scenario calls on the client. A missing one is a structured
     * `skipped` outcome rather than a failure.
     *
     * @return list<string>
     */
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['multi', 'discard', 'set', 'get', 'getMode'];
    }

    /**
     * Reason this client cannot run the scenario at all, or null when it can.
     * Checked before any call, so it must not depend on server state.
     */
    protected function unsupportedReason(Redis|RedisCluster|Relay|Cluster $client): ?string
    {
        return null;
    }

    abstract protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void;

    /** Abandon the scenario without failing it. */
    protected function skip(string $reason): never
    {
        throw new ScenarioSkipped($reason);
    }

    /** A second connection to the same server, or a skipped scenario. */
    protected function peer(
        ScenarioRun $run,
        ?ScenarioPeers $peers,
    ): Redis|RedisCluster|Relay|Cluster {
        $peer = $peers?->peerFor($run->client());
        if ($peer === null) {
            $this->skip('Scenario requires a second connection; no scenario peer is available');
        }

        return $peer;
    }

    /**
     * Client-class constant (for example PIPELINE), preferring the client's own
     * class so a Relay build that defines its own value is respected.
     */
    protected function clientConstant(
        Redis|RedisCluster|Relay|Cluster $client,
        string $name,
        int $default,
    ): int {
        $classes = [];
        if ($client instanceof Relay || $client instanceof Cluster) {
            $classes[] = Relay::class;
        }
        $classes[] = Redis::class;
        foreach ($classes as $class) {
            if (defined($class . '::' . $name)) {
                $value = constant($class . '::' . $name);
                if (is_int($value)) {
                    return $value;
                }
            }
        }

        return $default;
    }

    protected function isCluster(Redis|RedisCluster|Relay|Cluster $client): bool
    {
        return $client instanceof RedisCluster || $client instanceof Cluster;
    }
}
