<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Hooks;

interface InvocationHook
{
    public function beforeInvocation(PendingInvocation $invocation): HookDecision;
}
