<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\InvocationRecorder;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Builds scenario peers from a caller-supplied factory closure.
 *
 * The closure receives the scenario client and returns a second connection to
 * the same target, or null when the caller cannot build one. Peers are created
 * lazily, at most once per client, and are closed by {@see release()}.
 */
final class CallableScenarioPeers implements ScenarioPeers
{
    /** @var array<int, Redis|RedisCluster|Relay|Cluster|null> */
    private array $peers = [];

    /**
     * @param \Closure(Redis|RedisCluster|Relay|Cluster): (Redis|RedisCluster|Relay|Cluster|null) $factory
     */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public function peerFor(
        Redis|RedisCluster|Relay|Cluster $client,
    ): Redis|RedisCluster|Relay|Cluster|null {
        $id = spl_object_id($client);
        if (array_key_exists($id, $this->peers)) {
            return $this->peers[$id];
        }

        try {
            $peer = ($this->factory)($client);
        } catch (\Throwable) {
            /* A peer that cannot be built makes scenarios skip, never fail. */
            $peer = null;
        }

        /* A factory that handed back the same object cannot observe the
           watch state of another connection. */
        return $this->peers[$id] = $peer === $client ? null : $peer;
    }

    public function release(): void
    {
        foreach ($this->peers as $peer) {
            if ($peer === null) {
                continue;
            }
            try {
                InvocationRecorder::invoke(new StrictClientInvoker(), $peer, 'close', [],
                    'cleanup', 'scenario-peer release');
            } catch (\Throwable) {
                /* Closing a peer is best effort; the run owns nothing here. */
            }
        }
        $this->peers = [];
    }
}
