<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class StrictClientInvoker implements ClientInvoker
{
    public function invoke(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
    ): mixed {
        return $client->{$method}(...$arguments);
    }
}
