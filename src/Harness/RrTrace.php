<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Helpers for dealing with `rr record` trace directories.
 *
 * While recording, rr keeps an `incomplete` sentinel file in the trace
 * directory and removes it once the trace is consistent and replayable. A
 * directory that still holds the sentinel cannot be replayed at all, so one
 * must never be filed as a reproducer: `rr replay` on it fails outright, and
 * the operator only finds that out long after the campaign is over.
 */
final class RrTrace
{
    /** The sentinel rr keeps in a trace directory until the recording is final. */
    public const SENTINEL = 'incomplete';

    /** How much of a run's stderr to scan for rr's own diagnostics. */
    private const DIAGNOSTIC_TAIL = 65536;

    /** A trace directory that exists and no longer holds the sentinel. */
    public static function isComplete(string $traceDir): bool
    {
        if (!is_dir($traceDir)) {
            return false;
        }

        $sentinel = $traceDir . '/' . self::SENTINEL;
        clearstatcache(true, $sentinel);

        return !file_exists($sentinel);
    }

    /**
     * Block until rr has finalised the recording or $timeout seconds elapse.
     *
     * @return bool True when the trace finalised within the timeout.
     */
    public static function awaitComplete(string $traceDir, float $timeout): bool
    {
        if (!is_dir($traceDir)) {
            return false;
        }

        $deadline = microtime(true) + max(0.0, $timeout);

        do {
            if (self::isComplete($traceDir)) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return self::isComplete($traceDir);
    }

    /**
     * Why this trace cannot be replayed, or null when it is usable.
     *
     * A null $traceDir means the run was never recorded, which is not a
     * problem: only a run that was supposed to produce a trace can fail to.
     */
    public static function problem(?string $traceDir): ?string
    {
        if ($traceDir === null) {
            return null;
        }
        if (!is_dir($traceDir)) {
            return 'rr wrote no trace directory';
        }
        if (!self::isComplete($traceDir)) {
            return 'rr trace never finalised (incomplete)';
        }

        return null;
    }

    /**
     * rr's own fatal diagnostic from a run's stderr, if it printed one.
     *
     * rr aborts with `[FATAL src/File.cc:123:func()] message` when it cannot
     * record — for example when a signal (a terminal resize is the classic
     * one) stops the tracee in the window where `Task::spawn()` expects only
     * SIGSTOP. That is a failure of the recorder, not a finding about the
     * client under test, and there is no point waiting for a trace it was
     * never going to finish.
     */
    public static function fatalError(string $stderrPath): ?string
    {
        $size = @filesize($stderrPath);
        if ($size === false) {
            return null;
        }

        $offset = max(0, $size - self::DIAGNOSTIC_TAIL);
        $tail = @file_get_contents($stderrPath, false, null, $offset);
        if (!is_string($tail) || $tail === '') {
            return null;
        }

        if (preg_match('/^\[FATAL [^\]]*\]\s*(.+)$/m', $tail, $matches) !== 1) {
            return null;
        }

        return 'rr: ' . trim($matches[1]);
    }
}
