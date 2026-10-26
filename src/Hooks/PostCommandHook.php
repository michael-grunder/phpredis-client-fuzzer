<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Hooks;

interface PostCommandHook
{
    /** Runs after the client method returned or threw. */
    public function postCommand(CompletedInvocation $invocation): void;
}
