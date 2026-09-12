<?php

declare(strict_types=1);

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;

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
