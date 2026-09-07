<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * What a finished run's rr artifacts are worth.
 *
 * The two things that can be wrong with them are answered separately because
 * they mean different things to the campaign. A trace that never finalised is
 * a failure whose evidence cannot be replayed — worth keeping, worth counting.
 * rr printing its own fatal error is not a finding at all: the child the
 * harness launched *is* rr, so rr dying arrives as a crash of the run, and the
 * run says nothing about the client whatever state the trace was left in.
 */
final class TraceVerdict
{
    /**
     * @param string|null $rrFatal rr's own diagnostic, when it died of its own
     *        fault; the run then tells us nothing about the client.
     * @param string|null $problem Why the trace cannot be replayed, if it cannot.
     */
    public function __construct(
        public readonly ?string $rrFatal = null,
        public readonly ?string $problem = null,
    ) {
    }

    /** True when the artifacts can be trusted and replayed. */
    public function usable(): bool
    {
        return $this->rrFatal === null && $this->problem === null;
    }

    /** The operator-facing reason the artifacts are unusable, or null when they are not. */
    public function reason(): ?string
    {
        if ($this->rrFatal === null) {
            return $this->problem;
        }
        if ($this->problem === null) {
            return 'rr failed while recording: ' . $this->rrFatal;
        }

        return $this->problem . ': ' . $this->rrFatal;
    }
}
