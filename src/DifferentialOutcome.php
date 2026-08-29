<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

final readonly class DifferentialOutcome implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $arguments
     * @param list<string> $initialDifferences
     * @param list<string> $finalDifferences
     */
    public function __construct(
        public int $sequence,
        public string $command,
        public string $operation,
        public array $arguments,
        public string $referenceClientId,
        public string $subjectClientId,
        public string $status,
        public bool $initialMatch,
        public array $initialDifferences,
        public array $finalDifferences,
        public int $subjectAttempts,
        public ?float $convergenceSeconds,
        public float $toleranceMilliseconds,
        public DifferentialObservation $reference,
        public DifferentialObservation $subjectInitial,
        public DifferentialObservation $subjectFinal,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'sequence' => $this->sequence,
            'command' => $this->command,
            'operation' => $this->operation,
            'arguments' => $this->arguments,
            'reference_client_id' => $this->referenceClientId,
            'subject_client_id' => $this->subjectClientId,
            'status' => $this->status,
            'initial_match' => $this->initialMatch,
            'initial_differences' => $this->initialDifferences,
            'final_differences' => $this->finalDifferences,
            'subject_attempts' => $this->subjectAttempts,
            'convergence_seconds' => $this->convergenceSeconds,
            'tolerance_milliseconds' => $this->toleranceMilliseconds,
            'reference' => $this->reference,
            'subject_initial' => $this->subjectInitial,
            'subject_final' => $this->subjectFinal,
        ];
    }
}
