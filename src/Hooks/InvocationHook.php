<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

interface InvocationHook
{
    public function beforeInvocation(PendingInvocation $invocation): HookDecision;
}
