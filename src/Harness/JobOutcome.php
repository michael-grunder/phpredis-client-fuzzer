<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * The result of a synchronous re-run performed by the {@see Reducer}.
 */
final class JobOutcome
{
    public function __construct(
        public readonly FailureClassifier $verdict,
        public readonly string $workDir,
        public readonly ?string $traceDir,
        public readonly float $duration,
        public readonly ?int $pid,
        public readonly ?LeakReport $leak = null,
    ) {
    }
}
