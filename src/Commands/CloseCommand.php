<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

/**
 * close()/pclose() followed by an explicit reconnect for standalone Relay.
 *
 * PhpRedis reconnects lazily on the next command, but a closed standalone
 * Relay throws "Not connected" forever, which turned the rest of every such
 * run into identical exceptions. Reconnecting also exercises Relay's
 * reconnect path (tracking, cache flush) on a client with outstanding state.
 */
class CloseCommand extends EmptyLocalCommand {
    public function flags(): int {
        return self::CONNECTION;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if ( ! method_exists($client, $this->name()))
            return null;

        /* Relay's getters throw once closed, and a bare connect() resets the
           timeouts to unlimited, so capture everything while still open. */
        $endpoint = null;
        if ($client instanceof Relay) {
            try {
                $auth = $client->getAuth();
                $endpoint = [
                    $client->getHost(), $client->getPort(),
                    (float)$client->getTimeout(), null, 0,
                    (float)$client->getReadTimeout(),
                    $auth === null || $auth === false ? [] : ['auth' => $auth],
                ];
            } catch (\Throwable) {
                /* Already closed: nothing to restore. */
            }
        }

        $result = $this->exec($client);

        if ($endpoint !== null)
            $this->cmd($client, 'connect', ...$endpoint);

        return $result;
    }
}
