<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

/**
 * Optional companion to a lifecycle hook that configured an external fault
 * source before the script was opened. Emit its saved setup for replay only;
 * this must not invoke clients, draw randomness, or re-arm the running target.
 */
interface ScriptSetupHook
{
    public function recordScriptSetup(): void;
}
