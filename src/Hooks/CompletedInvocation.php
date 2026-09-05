<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Payload for HookEvent::PostCommand. Exactly one of $result and $throwable is
 * meaningful: a client that threw has no reply.
 */
final readonly class CompletedInvocation
{
    /**
     * @param list<mixed> $arguments Runtime arguments as they were dispatched.
     */
    public function __construct(
        public string $command,
        public Redis|RedisCluster|Relay|Cluster $client,
        public string $method,
        public array $arguments,
        public mixed $result,
        public ?\Throwable $throwable,
        public float $durationSeconds,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->throwable === null;
    }
}
