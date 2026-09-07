<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * An append-only record of the runs rr aborted on.
 *
 * Such a run is discarded rather than captured: it is a recorder failure, not
 * a client failure, and keeping a multi-gigabyte trace for each one only
 * buries the reproducers that matter. One line each is still worth having —
 * a campaign that loses a handful of runs to rr is normal, a campaign that
 * loses most of them is a problem with the machine or with two harnesses
 * sharing an output directory, and the difference is only visible if someone
 * counted.
 *
 * Several campaigns share one output directory, so lines are appended under an
 * exclusive lock and carry the harness pid that wrote them.
 */
final class RrAbortLog
{
    /** The file this log is written to, inside the output directory. */
    public const FILE = 'rr-aborts.log';

    public function __construct(private readonly string $path)
    {
    }

    public static function inDirectory(string $dir): self
    {
        return new self($dir . '/' . self::FILE);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param int $harnessPid The campaign the run belonged to.
     * @param string $message rr's own diagnostic.
     * @param string|null $workDir The retained work directory (--keep-work), if
     *        it was kept; without it the run's artifacts are gone by now.
     */
    public function append(int $harnessPid, Job $job, string $message, ?string $workDir = null): void
    {
        $line = sprintf(
            "%s harness=%d run=%d seed=%d%s %.1fs :: %s%s\n",
            date('c'),
            $harnessPid,
            $job->id,
            $job->seed,
            $job->port !== null ? ' port=' . $job->port : '',
            $job->duration(),
            self::oneLine($message),
            $workDir !== null ? ' work=' . $workDir : '',
        );

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    /** rr's diagnostics are single-line, but never let one break the log's shape. */
    private static function oneLine(string $message): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $message);

        return trim($collapsed ?? $message);
    }
}
