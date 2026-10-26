<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

/**
 * Both clients' executions of one testcase and how they compare.
 *
 * @phpstan-import-type Execution from DiffRuntime
 * @phpstan-import-type Comparison from DiffRuntime
 */
final readonly class RunPair
{
    /**
     * @param Execution $first The client named first in the case's order.
     * @param Execution $second
     * @param Comparison $comparison
     */
    public function __construct(
        public TestCase $case,
        public array $first,
        public array $second,
        public array $comparison,
    ) {
    }

    public function diverged(): bool
    {
        return $this->comparison['diverged'];
    }

    /**
     * What kind of divergence this is, stable across re-runs and minimization:
     * the class of the first divergence and the operation it happened at.
     * State divergence that precedes the first reply divergence wins, because
     * the operation that corrupted state is the real culprit.
     */
    public function signature(): ?string
    {
        $comparison = $this->comparison;
        if (!$comparison['diverged']) {
            return null;
        }
        if ($comparison['initial'] !== null) {
            return 'initial-state';
        }

        $reply = $comparison['reply'];
        $state = $comparison['state'];
        if ($state !== null && $state['index'] !== null && ($reply === null || $state['index'] < $reply['index'])) {
            return 'state:' . $this->method($state['index']);
        }
        if ($reply !== null) {
            return 'reply:' . $this->method($reply['index']) . ':' . $reply['detail'];
        }

        return 'state:final';
    }

    /** Index of the first operation at which the runs diverge, if known. */
    public function firstIndex(): ?int
    {
        $reply = $this->comparison['reply']['index'] ?? null;
        $state = $this->comparison['state']['index'] ?? null;
        if ($reply === null || $state === null) {
            return $reply ?? $state;
        }

        return min($reply, $state);
    }

    public function summary(): string
    {
        $parts = [];
        $comparison = $this->comparison;
        if ($comparison['initial'] !== null) {
            $parts[] = 'initial state differs: ' . $comparison['initial'];
        }
        if ($comparison['reply'] !== null) {
            $index = $comparison['reply']['index'];
            $parts[] = sprintf('reply@%d %s: %s', $index, $this->method($index), $comparison['reply']['detail']);
        }
        if ($comparison['state'] !== null) {
            $index = $comparison['state']['index'];
            $parts[] = $index === null
                ? 'final state differs'
                : sprintf('state@%d %s', $index, $this->method($index));
        }

        return $parts === [] ? 'no divergence' : implode('; ', $parts);
    }

    private function method(int $index): string
    {
        return $this->case->test[$index][0] ?? '?';
    }
}
