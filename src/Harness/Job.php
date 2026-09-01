<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * One fuzzer run: its immutable launch parameters plus the mutable state the
 * scheduler and runner update as it executes and is classified.
 */
final class Job
{
    public JobStatus $status = JobStatus::Running;

    public ?int $pid = null;

    public ?int $exitCode = null;

    public ?int $signal = null;

    public bool $timedOut = false;

    /** True once the run has been judged a failure, regardless of capture. */
    public bool $failure = false;

    public ?float $finishedAt = null;

    public ?string $reproDir = null;

    public ?int $reducedSteps = null;

    public string $note = '';

    /**
     * Cached proc_get_status() from the moment the child was reaped; a second
     * call to proc_get_status() would lose the real exit code and signal.
     *
     * @var array{
     *     command: string, pid: int, running: bool, signaled: bool,
     *     stopped: bool, exitcode: int, termsig: int, stopsig: int
     * }|null
     */
    public ?array $reapedStatus = null;

    /** @var resource */
    public $process;

    /**
     * @param list<string> $argv Fully resolved argv passed to proc_open().
     * @param resource $process
     */
    public function __construct(
        public readonly int $id,
        public readonly int $slot,
        public readonly ?int $port,
        public readonly int $steps,
        public readonly int $seed,
        public readonly string $workDir,
        public readonly ?string $traceDir,
        public readonly array $argv,
        public readonly float $startedAt,
        $process,
    ) {
        $this->process = $process;
    }

    public function isRunning(): bool
    {
        return $this->status === JobStatus::Running;
    }

    public function duration(): float
    {
        return ($this->finishedAt ?? microtime(true)) - $this->startedAt;
    }
}
