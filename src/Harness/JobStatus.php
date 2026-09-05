<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

enum JobStatus: string
{
    case Running = 'running';

    /** Finished cleanly (exit 0). */
    case Passed = 'passed';

    /** Failed, but not captured (its category is not in --capture, or the run
     *  was discarded during shutdown). */
    case Skipped = 'skipped';

    /** Non-crash failure (the fuzzer exited non-zero), captured as a reproducer. */
    case Failed = 'failed';

    /** Died from a crashing signal, captured as a reproducer. */
    case Crashed = 'crashed';

    /** Exceeded --run-timeout and was killed, captured as a reproducer. */
    case TimedOut = 'timedout';

    /** A debug PHP build reported a Zend MM memory leak, captured as a reproducer. */
    case Leaked = 'leaked';

    /**
     * The fuzzer rejected its command line (ExitCode::STARTUP) and never ran a
     * command, so every run of this campaign would fail the same way.
     */
    case StartupFailed = 'startup-failed';

    /**
     * The run failed, but its artifacts are not replayable — an rr trace that
     * never finalised, most often because rr itself died before it could
     * record anything. Parked under `<output>/failed/` instead of being filed
     * as a reproducer.
     */
    case CaptureFailed = 'capture-failed';
}
