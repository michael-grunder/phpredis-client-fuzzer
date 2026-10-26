<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Supplies a second connection to the same server a scenario client uses.
 *
 * Scenarios that have to prove watch installation or removal need another
 * connection to mutate the watched key. Embedded callers own their clients, so
 * this stays optional: a scenario that cannot obtain a peer reports `skipped`.
 */
interface ScenarioPeers
{
    /**
     * Return an independent connection to the same target as `$client`,
     * configured the same way (prefix, database, serializer), or null when no
     * peer can be created.
     *
     * Implementations should reuse one peer per client across scenarios.
     */
    public function peerFor(
        Redis|RedisCluster|Relay|Cluster $client,
    ): Redis|RedisCluster|Relay|Cluster|null;

    /** Close every connection this provider created. */
    public function release(): void;
}
