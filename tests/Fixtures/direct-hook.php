<?php

declare(strict_types=1);

use Mgrunder\RedisClientTester\Hooks\HookDecision;
use Mgrunder\RedisClientTester\Hooks\InvocationHook;
use Mgrunder\RedisClientTester\Hooks\PendingInvocation;

/** @var array<string, string> $arguments */
return new class($arguments['reason'] ?? 'direct hook fixture') implements InvocationHook {
    public function __construct(private readonly string $reason)
    {
    }

    public function beforeInvocation(PendingInvocation $invocation): HookDecision
    {
        return HookDecision::reject($this->reason);
    }
};
