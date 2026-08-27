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
            'warnings' => $this->warnings,
        ];
    }
}
