<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

/**
 * Observation points hook code can subscribe to.
 *
 * Unlike InvocationHook, listeners for these events only observe: they cannot
 * reject or rewrite what the fuzzer is about to do.
 */
enum HookEvent: string
{
    /** A client object became available to the fuzzer. */
    case PostConstructor = 'postConstructor';

    /** A client method is about to be invoked with final arguments. */
    case PreCommand = 'preCommand';

    /** A client method returned or threw. */
    case PostCommand = 'postCommand';

    /** The fuzzer is done using a client object. */
    case PreDestructor = 'preDestructor';
}
