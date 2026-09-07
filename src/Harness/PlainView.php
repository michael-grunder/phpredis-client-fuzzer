<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * A non-interactive view: periodic summary lines plus an event line per run.
 * Used when stdout is not a TTY or `--no-tui` is set.
 */
final class PlainView implements View
{
    /** @var resource */
    private $out;

    private float $lastSummary = 0.0;

    private readonly bool $quiet;

    /** @param resource|null $out */
    public function __construct(bool $quiet = false, $out = null)
    {
        $this->quiet = $quiet;
        $this->out = $out ?? STDOUT;
    }

    public function start(): void
    {
        $this->line('harness: starting');
    }

    public function render(Stats $stats, DashboardState $state): void
    {
        $now = microtime(true);
        if ($now - $this->lastSummary < 2.0) {
            return;
        }
        $this->lastSummary = $now;

        $this->line(sprintf(
            '[%s] runs=%d active=%d/%d exec/s=%.1f failures=%d crashes=%d timeouts=%d leaks=%d repros=%d'
            . ($stats->failedReproducers > 0 ? ' failed-repros=' . $stats->failedReproducers : '')
            . ($stats->rrAborts > 0 ? ' rr-aborts=' . $stats->rrAborts : ''),
            $stats->formatElapsed(),
            $stats->completed,
            count($state->active),
            $state->jobs,
            $stats->execPerSecond(),
            $stats->failures,
            $stats->crashes,
            $stats->timeouts,
            $stats->leaks,
            $stats->reproducers,
        ));
    }

    /**
     * Quiet mode keeps the campaign-level warnings and the per-run lines that
     * mean something happened to the client. The lowercase 'rr aborted' match
     * is deliberate: it lets the scheduler's summary of recorder failures
     * through while filtering the per-run 'RR ABORTED' lines, which are the
     * noise the summary is counting.
     */
    public function note(string $message): void
    {
        if ($this->quiet && !str_contains($message, 'CRASH') && !str_contains($message, 'FAIL')
            && !str_contains($message, 'TIMEOUT') && !str_contains($message, 'LEAK')
            && !str_contains($message, 'trace capture failed')
            && !str_contains($message, 'rr aborted')) {
            return;
        }
        $this->line('harness: ' . $message);
    }

    public function quitRequested(): bool
    {
        return false;
    }

    public function stop(): void
    {
    }

    private function line(string $message): void
    {
        fwrite($this->out, $message . "\n");
    }
}
