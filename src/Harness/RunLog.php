<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * An append-only transcript of what the fuzzer runs themselves printed.
 *
 * Every run's stdout is already captured in its work directory, but that
 * directory is either moved into a reproducer or deleted the moment the run is
 * classified, so the output of a run that passed is normally thrown away.
 * `--run-log` keeps all of it in one file that can be tailed, grepped, or fed
 * to `jq`, which is the only way to see the *aggregate* of a campaign — how
 * often a Redis error shows up, which commands are actually being selected,
 * what Relay's memory did — rather than only the runs that failed.
 *
 * Each run contributes one block: a `#` header naming the run and how it ended,
 * followed by that run's stdout verbatim. Blocks are written whole, by the
 * harness process, after the child has been reaped; pointing several children's
 * stdout at one shared file instead would shred the fuzzer's pretty-printed
 * JSON documents into each other. The cost is that a run appears in the log
 * when it finishes rather than as it runs.
 *
 * Several campaigns may share one log, so blocks are appended under an
 * exclusive lock and every header carries the harness pid that wrote it.
 */
final class RunLog
{
    public function __construct(
        private readonly string $path,
        private readonly int $harnessPid,
    ) {
    }

    /**
     * Verify the log can be appended to before any run is spawned, so a
     * misspelled path is a startup error rather than a campaign that silently
     * logs nothing. An existing log is never truncated: it may hold the only
     * record of an earlier campaign.
     */
    public static function open(string $path, int $harnessPid): self
    {
        $handle = @fopen($path, 'a');
        if ($handle === false) {
            throw new \RuntimeException("cannot open --run-log for appending: {$path}");
        }
        fclose($handle);

        return new self($path, $harnessPid);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Append one finished run's stdout.
     *
     * @param string $verdict A short label for how the run ended, e.g. `ok`,
     *        `SIGSEGV`, `timeout (SIGKILL)`, `exit 1`.
     */
    public function append(Job $job, string $verdict): void
    {
        $handle = @fopen($this->path, 'a');
        if ($handle === false) {
            // The log is a convenience; losing it must never cost the campaign
            // a run or a capture.
            return;
        }

        @flock($handle, LOCK_EX);
        fwrite($handle, $this->header($job, $verdict));
        if (!self::copy($job->workDir . '/stdout.log', $handle)) {
            fwrite($handle, "# (no output)\n");
        }
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function header(Job $job, string $verdict): string
    {
        return sprintf(
            "# %s harness=%d run=%d seed=%d%s steps=%d pid=%s %.2fs :: %s\n",
            date('c'),
            $this->harnessPid,
            $job->id,
            $job->seed,
            $job->port !== null ? ' port=' . $job->port : '',
            $job->steps,
            $job->pid ?? '?',
            $job->duration(),
            self::oneLine($verdict),
        );
    }

    /**
     * Stream $source into $destination. Copied rather than read into memory
     * because a run's output is unbounded — a long campaign step budget with
     * `--output=detailed` produces a lot of it.
     *
     * @param resource $destination
     * @return bool Whether anything was written.
     */
    private static function copy(string $source, $destination): bool
    {
        $handle = @fopen($source, 'r');
        if ($handle === false) {
            return false;
        }

        $stat = fstat($handle);
        if ($stat === false || $stat['size'] <= 0) {
            fclose($handle);
            return false;
        }

        stream_copy_to_stream($handle, $destination);

        // A run killed mid-write can leave its last line unterminated; the next
        // block's header has to start on a line of its own for the log to stay
        // greppable.
        if (fseek($handle, -1, SEEK_END) === 0 && fread($handle, 1) !== "\n") {
            fwrite($destination, "\n");
        }
        fclose($handle);

        return true;
    }

    /** Verdict labels are single-line, but never let one break the log's shape. */
    private static function oneLine(string $verdict): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $verdict);

        return trim($collapsed ?? $verdict);
    }
}
