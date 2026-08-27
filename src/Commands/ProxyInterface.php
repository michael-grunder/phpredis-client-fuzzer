<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

interface ProxyInterface {
    /* Proxy commands from a source Redis server to a client redis server.
       This allows us to fuzz the client by proxying key names/values from
       the server. */
    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed;
}
