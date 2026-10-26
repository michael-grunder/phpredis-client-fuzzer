<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Hooks;

interface PreCommandHook
{
    /** Runs immediately before the client method is invoked. */
    public function preCommand(PendingInvocation $invocation): void;
}
