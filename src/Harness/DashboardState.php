<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * An immutable snapshot handed to the {@see View} on every render tick.
 */
final class DashboardState
{
    /**
     * @param list<int> $ports
     * @param list<'crashes'|'leaks'|'failures'> $capture
     * @param list<Job> $active Currently running jobs.
     * @param list<Job> $recent Finished jobs, newest first.
     */
    public function __construct(
        public readonly int $jobs,
        public readonly int $steps,
        public readonly array $ports,
        public readonly bool $rr,
        public readonly bool $rrChaos,
        public readonly array $capture,
        public readonly ?string $reduce,
        public readonly string $output,
        public readonly string $phpVersion,
        public readonly array $active,
        public readonly array $recent,
        public readonly ?int $maxRuns,
        public readonly ?int $maxReproducers,
        public readonly ?float $maxSeconds,
    ) {
    }
}
