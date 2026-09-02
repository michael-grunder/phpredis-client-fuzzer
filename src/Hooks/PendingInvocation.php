<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final readonly class PendingInvocation
{
    /**
     * @param list<mixed> $arguments Runtime arguments after ScriptArg values are resolved.
     */
    public function __construct(
        public string $command,
        public Redis|RedisCluster|Relay|Cluster $client,
        public string $method,
        public array $arguments,
    ) {
    }

    /** @param list<mixed> $arguments */
    public function withArguments(array $arguments): self
    {
        return new self(
            $this->command,
            $this->client,
            $this->method,
            $arguments,
        );
    }
}
