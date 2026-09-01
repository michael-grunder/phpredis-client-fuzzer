<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Helpers for dealing with `rr record` trace directories.
 */
final class RrTrace
{
    /**
     * Block until rr has finalised the recording or $timeout seconds elapse.
     *
     * While recording, rr keeps an `incomplete` sentinel file in the trace
     * directory and removes it once the trace is consistent and replayable.
     * Copying or moving the directory before then yields a useless trace.
     *
     * @return bool True when the trace finalised within the timeout.
     */
    public static function awaitComplete(string $traceDir, float $timeout): bool
    {
        if (!is_dir($traceDir)) {
            return false;
        }

        $sentinel = $traceDir . '/incomplete';
        $deadline = microtime(true) + max(0.0, $timeout);

        do {
            clearstatcache(true, $sentinel);
            if (!file_exists($sentinel)) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        clearstatcache(true, $sentinel);

        return !file_exists($sentinel);
    }
}
