<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Payload for the client lifecycle events. The client is handed over as-is;
 * hook code decides for itself whether it is looking at PhpRedis or Relay and
 * at a standalone or cluster client.
 */
final readonly class ClientEvent
{
    /**
     * @param int|null $clientIndex Position in the run's client list, or null
     *        when the client is not (yet) part of a run.
     */
    public function __construct(
        public HookEvent $event,
        public Redis|RedisCluster|Relay|Cluster $client,
        public ?int $clientIndex = null,
    ) {
    }

    public function isCluster(): bool
    {
        return $this->client instanceof RedisCluster || $this->client instanceof Cluster;
    }

    public function isRelay(): bool
    {
        return $this->client instanceof Relay || $this->client instanceof Cluster;
    }
}
