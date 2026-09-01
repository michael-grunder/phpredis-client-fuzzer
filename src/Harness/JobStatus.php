<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

enum JobStatus: string
{
    case Running = 'running';

    /** Finished cleanly (exit 0). */
    case Passed = 'passed';

    /** Failed, but not captured (a non-crash failure while --only-crashes is set,
     *  or a run discarded during shutdown). */
    case Skipped = 'skipped';

    /** Non-crash failure (the fuzzer exited non-zero), captured as a reproducer. */
    case Failed = 'failed';

    /** Died from a crashing signal, captured as a reproducer. */
    case Crashed = 'crashed';

    /** Exceeded --run-timeout and was killed, captured as a reproducer. */
    case TimedOut = 'timedout';
}
