<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Shrinks a captured failure by binary-searching the smallest `{steps}` value
 * that still reproduces it, with the same seed and port. This keeps the
 * campaign moving while turning a 5000-step crash into a handful of commands.
 */
final class Reducer
{
    public function __construct(
        private readonly JobRunner $runner,
        private readonly float $budgetSeconds,
    ) {
    }

    /**
     * @return array{steps: int, outcome: JobOutcome}|null
     *         The smallest reproducing step count and its (rr-less) re-run, or
     *         null when nothing smaller reproduced within the time budget.
     */
    public function reduce(Job $job): ?array
    {
        if ($job->steps <= 1) {
            return null;
        }

        $expected = FailureClassifier::fromExit(
            $job->signal !== null,
            $job->signal,
            $job->exitCode,
            $job->timedOut,
            $job->leak !== null,
        );
        $trialTimeout = max(10.0, $job->duration() * 4.0);
        $deadline = microtime(true) + $this->budgetSeconds;

        $low = 1;
        $high = $job->steps - 1;
        /** @var array{steps: int, outcome: JobOutcome}|null $best */
        $best = null;

        while ($low <= $high && microtime(true) < $deadline) {
            $mid = intdiv($low + $high, 2);
            $outcome = $this->runner->rerun($job->seed, $job->port, $mid, $trialTimeout, false);

            if ($expected->matches($outcome->verdict)) {
                if ($best !== null) {
                    $this->runner->cleanupOutcome($best['outcome']);
                }
                $best = ['steps' => $mid, 'outcome' => $outcome];
                $high = $mid - 1;
            } else {
                $this->runner->cleanupOutcome($outcome);
                $low = $mid + 1;
            }
        }

        return $best;
    }
}
