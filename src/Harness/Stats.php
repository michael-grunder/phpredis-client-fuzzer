<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Running counters for the campaign, shown in the dashboard header.
 */
final class Stats
{
    public int $started = 0;

    public int $completed = 0;

    public int $passed = 0;

    public int $skipped = 0;

    public int $failures = 0;

    public int $crashes = 0;

    public int $timeouts = 0;

    public int $leaks = 0;

    public int $reproducers = 0;

    public int $reductions = 0;

    /** Runs that exited with ExitCode::STARTUP without fuzzing anything. */
    public int $startupFailures = 0;

    private readonly float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function elapsed(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function execPerSecond(): float
    {
        $elapsed = $this->elapsed();

        return $elapsed > 0.0 ? $this->completed / $elapsed : 0.0;
    }

    public function formatElapsed(): string
    {
        $seconds = (int) $this->elapsed();

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }
}
