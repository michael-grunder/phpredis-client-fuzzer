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
     * @param list<\Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulOutcome> $statefulOutcomes
     * @param array{samples: int, hits: int, misses: int, oom: int, evictions?: int, memory: array{total: int, limit: int, active: int, used: int, peak_active: int, peak_used: int}}|null $relayStats
     * @param list<InvocationOutcome> $saturationOutcomes
     * @param array<string, array{count: int, reason: string}> $hookRejections
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
        public array $statefulOutcomes = [],
        public ?array $relayStats = null,
        public int $saturationEvents = 0,
        public array $saturationOutcomes = [],
        public array $hookRejections = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'seed' => $this->seed,
            'steps' => $this->steps,
            'cross_slot_steps' => $this->crossSlotSteps,
            'saturation_events' => $this->saturationEvents,
            'saturation_reads' => count($this->saturationOutcomes),
            'elapsed_seconds' => $this->elapsedSeconds,
            'selected_commands' => $this->selectedCommands,
            'environment' => $this->environment,
            'configuration' => $this->configuration,
            'commands' => $this->commands,
            'outcomes' => $this->outcomes,
            'differential_outcomes' => $this->differentialOutcomes,
            'stateful_outcomes' => $this->statefulOutcomes,
            'saturation_outcomes' => $this->saturationOutcomes,
            'hook_rejections' => $this->hookRejections,
            'relay_stats' => $this->relayStats,
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

    public function hasStatefulFailure(): bool
    {
        foreach ($this->statefulOutcomes as $outcome) {
            if ($outcome->status === 'failed') {
                return true;
            }
        }

        return false;
    }
}
