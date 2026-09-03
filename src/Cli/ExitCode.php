<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

/**
 * Process exit codes shared by the fuzzer binaries.
 *
 * {@see ExitCode::STARTUP} is a contract with the parallel harness: it means
 * the process never reached its workload because the command line was rejected
 * or described an unusable configuration. Repeating that command can only
 * produce the same rejection, so the harness stops the campaign and shows the
 * child's error instead of collecting thousands of identical failures.
 */
final class ExitCode
{
    /** The run completed and nothing was caught. */
    public const SUCCESS = 0;

    /** The run executed and something failed (a caught diagnostic, a divergence, ...). */
    public const FAILURE = 1;

    /**
     * The command line was rejected or the configuration is unusable; no work
     * was attempted. The value is sysexits.h EX_CONFIG.
     */
    public const STARTUP = 78;
}
