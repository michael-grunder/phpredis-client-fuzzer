<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

final readonly class RunConfiguration implements \JsonSerializable
{
    /**
     * @param list<string> $commands Command/glob/flag filters, such as get*, -getex, or @read.
     * @param list<string> $scenarios Named stateful scenarios to run before random steps.
     * @param array<string, float> $weights Command or @flag weights.
     */
    public function __construct(
        public int $maxSteps = 100,
        public float $maxSeconds = 0.0,
        public ?int $seed = null,
        public int $keys = 100,
        public int $shards = 16,
        public int $members = 10,
        public int $minLength = 4,
        public int $maxLength = 32,
        public int $maxKeysPerCommand = 10,
        public int $maxPrefixLength = 0,
        public float $wrongTypeChance = 0.0,
        public float $crossSlotChance = 0.0,
        public array $commands = [],
        public array $weights = [],
        public bool $raw = false,
        public bool $includeBlocking = false,
        public bool $includeLocal = false,
        public bool $includeAdmin = false,
        public bool $includeFlush = false,
        public bool $includeCrashing = false,
        public ?string $scriptLog = null,
        public ?string $catchPattern = null,
        public bool $differential = false,
        public float $differentialToleranceMs = 10.0,
        public float $differentialPollIntervalMs = 1.0,
        public array $scenarios = [],
        public bool $includeStateful = false,
        public InvocationMode $invocationMode = InvocationMode::Strict,
        public float $saturateChance = 0.0,
        public ?int $saturateSteps = null,
    ) {
        if ($maxSteps < 0) {
            throw new \InvalidArgumentException('maxSteps cannot be negative');
        }
        if ($maxSteps === 0 && $maxSeconds === 0.0) {
            throw new \InvalidArgumentException('At least one run limit must be greater than zero');
        }
        if ($maxSeconds < 0) {
            throw new \InvalidArgumentException('maxSeconds cannot be negative');
        }
        if ($keys < 1 || $shards < 1 || $members < 1 || $maxKeysPerCommand < 1) {
            throw new \InvalidArgumentException('Key, shard, member, and per-command limits must be positive');
        }
        if ($minLength < 1 || $maxLength < $minLength || $maxPrefixLength < 0) {
            throw new \InvalidArgumentException('Invalid generated string length limits');
        }
        if ($wrongTypeChance < 0.0 || $wrongTypeChance > 1.0) {
            throw new \InvalidArgumentException('wrongTypeChance must be between 0 and 1');
        }
        if ($crossSlotChance < 0.0 || $crossSlotChance > 1.0) {
            throw new \InvalidArgumentException('crossSlotChance must be between 0 and 1');
        }
        if ($saturateChance < 0.0 || $saturateChance > 1.0) {
            throw new \InvalidArgumentException('saturateChance must be between 0 and 1');
        }
        if ($saturateSteps !== null && $saturateSteps < 1) {
            throw new \InvalidArgumentException('saturateSteps must be positive when provided');
        }
        if ($catchPattern === '') {
            throw new \InvalidArgumentException('catchPattern cannot be empty');
        }
        if (!is_finite($differentialToleranceMs) || $differentialToleranceMs < 0.0) {
            throw new \InvalidArgumentException('differentialToleranceMs must be finite and non-negative');
        }
        if (!is_finite($differentialPollIntervalMs) || $differentialPollIntervalMs <= 0.0) {
            throw new \InvalidArgumentException('differentialPollIntervalMs must be finite and greater than zero');
        }
        foreach ($scenarios as $scenario) {
            if (trim($scenario) === '') {
                throw new \InvalidArgumentException('scenarios must contain non-empty names');
            }
        }
        foreach ($weights as $name => $weight) {
            if ($name === '' || $weight < 0.0) {
                throw new \InvalidArgumentException('Weights require a name and a non-negative value');
            }
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'maxSteps' => $this->maxSteps,
            'maxSeconds' => $this->maxSeconds,
            'seed' => $this->seed,
            'keys' => $this->keys,
            'shards' => $this->shards,
            'members' => $this->members,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'maxKeysPerCommand' => $this->maxKeysPerCommand,
            'maxPrefixLength' => $this->maxPrefixLength,
            'wrongTypeChance' => $this->wrongTypeChance,
            'crossSlotChance' => $this->crossSlotChance,
            'saturateChance' => $this->saturateChance,
            'saturateSteps' => $this->saturateSteps,
            'commands' => $this->commands,
            'weights' => $this->weights,
            'raw' => $this->raw,
            'includeBlocking' => $this->includeBlocking,
            'includeLocal' => $this->includeLocal,
            'includeAdmin' => $this->includeAdmin,
            'includeFlush' => $this->includeFlush,
            'includeCrashing' => $this->includeCrashing,
            'includeStateful' => $this->includeStateful,
            'scriptLog' => $this->scriptLog,
            'catchPattern' => $this->catchPattern,
            'differential' => $this->differential,
            'differentialToleranceMs' => $this->differentialToleranceMs,
            'differentialPollIntervalMs' => $this->differentialPollIntervalMs,
            'scenarios' => $this->scenarios,
            'invocationMode' => $this->invocationMode->value,
        ];
    }
}
