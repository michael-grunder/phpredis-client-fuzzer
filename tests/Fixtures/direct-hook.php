<?php

declare(strict_types=1);

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;

return new class implements InvocationHook {
    public function beforeInvocation(PendingInvocation $invocation): HookDecision
    {
        return HookDecision::reject('direct hook fixture');
    }
};
