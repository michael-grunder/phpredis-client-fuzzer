<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

final readonly class FuzzResult implements \JsonSerializable
{
    /**
     * @param array<string, array{count: int, replies: array<string, int>, exceptions: array<string, int>}> $commands
     * @param array<string, int> $warnings
     * @param list<string> $selectedCommands
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $configuration
     * @param array<string, array<string, int>> $commandWarnings
     * @param array<string, array{executions: int, false_replies: int}> $problematicCommands
     * @param list<InvocationOutcome> $outcomes
     * @param list<DifferentialOutcome> $differentialOutcomes
     */
    public function __construct(
        public int $seed,
        public int $steps,
        public float $elapsedSeconds,
        public array $commands,
        public array $warnings,
        public array $selectedCommands,
        public array $environment,
        public array $configuration,
        public int $crossSlotSteps = 0,
        public array $commandWarnings = [],
        public ?string $caughtDiagnostic = null,
        public array $problematicCommands = [],
        public array $outcomes = [],
        public array $differentialOutcomes = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'seed' => $this->seed,
            'steps' => $this->steps,
            'cross_slot_steps' => $this->crossSlotSteps,
            'elapsed_seconds' => $this->elapsedSeconds,
            'selected_commands' => $this->selectedCommands,
            'environment' => $this->environment,
            'configuration' => $this->configuration,
            'commands' => $this->commands,
            'outcomes' => $this->outcomes,
            'differential_outcomes' => $this->differentialOutcomes,
            'problematic_commands' => $this->problematicCommands,
            'warnings' => $this->warnings,
            'caught_diagnostic' => $this->caughtDiagnostic,
        ];
    }

    public function hasDifferentialDivergence(): bool
    {
        foreach ($this->differentialOutcomes as $outcome) {
            if ($outcome->status === 'divergent') {
                return true;
            }
        }

        return false;
    }
}
