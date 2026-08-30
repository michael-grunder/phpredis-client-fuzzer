<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Http;

use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class FuzzerEndpoint
{
    /** @var callable(): (Redis|RedisCluster|Relay|Cluster|non-empty-list<Redis|RedisCluster|Relay|Cluster>) */
    private $clientProvider;

    /**
     * @param callable(): (Redis|RedisCluster|Relay|Cluster|non-empty-list<Redis|RedisCluster|Relay|Cluster>) $clientProvider
     */
    public function __construct(
        callable $clientProvider,
        private readonly RunConfiguration $configuration = new RunConfiguration(maxSteps: 25),
    ) {
        $this->clientProvider = $clientProvider;
    }

    /**
     * Only seed, steps, seconds, and a narrower command filter are accepted
     * from the request. Dangerous category switches stay server-controlled.
     *
     * @param array<string, scalar|array<scalar>|null> $parameters
     * @return array{ok: true, result: array<string, mixed>}|array{ok: false, error: string}
     */
    public function handle(array $parameters = []): array
    {
        try {
            $configuration = $this->requestConfiguration($parameters);
            $provided = ($this->clientProvider)();
            $clients = is_array($provided) ? $provided : [$provided];
            /** @var non-empty-list<Redis|RedisCluster|Relay|Cluster> $clients */
            $result = (new Fuzzer())->run($clients, $configuration);

            return ['ok' => true, 'result' => $result->jsonSerialize()];
        } catch (\Throwable $throwable) {
            return ['ok' => false, 'error' => $throwable->getMessage()];
        }
    }

    /** @param array<string, scalar|array<scalar>|null> $parameters */
    public function handleJson(array $parameters = []): string
    {
        return json_encode($this->handle($parameters), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string, scalar|array<scalar>|null> $parameters
     */
    private function requestConfiguration(array $parameters): RunConfiguration
    {
        $steps = $this->boundedInt($parameters['steps'] ?? null, $this->configuration->maxSteps);
        $seconds = $this->boundedFloat($parameters['seconds'] ?? null, $this->configuration->maxSeconds);
        $seed = $this->optionalInt($parameters['seed'] ?? null) ?? $this->configuration->seed;
        $commands = $this->commands($parameters['commands'] ?? null) ?? $this->configuration->commands;

        return new RunConfiguration(
            maxSteps: $steps,
            maxSeconds: $seconds,
            seed: $seed,
            keys: $this->configuration->keys,
            shards: $this->configuration->shards,
            members: $this->configuration->members,
            minLength: $this->configuration->minLength,
            maxLength: $this->configuration->maxLength,
            maxKeysPerCommand: $this->configuration->maxKeysPerCommand,
            maxPrefixLength: $this->configuration->maxPrefixLength,
            wrongTypeChance: $this->configuration->wrongTypeChance,
            crossSlotChance: $this->configuration->crossSlotChance,
            commands: $commands,
            weights: $this->configuration->weights,
            raw: $this->configuration->raw,
            includeBlocking: $this->configuration->includeBlocking,
            includeLocal: $this->configuration->includeLocal,
            includeAdmin: $this->configuration->includeAdmin,
            includeFlush: $this->configuration->includeFlush,
            includeCrashing: $this->configuration->includeCrashing,
            includeStateful: $this->configuration->includeStateful,
            scriptLog: $this->configuration->scriptLog,
            catchPattern: $this->configuration->catchPattern,
            differential: $this->configuration->differential,
            differentialToleranceMs: $this->configuration->differentialToleranceMs,
            differentialPollIntervalMs: $this->configuration->differentialPollIntervalMs,
            scenarios: $this->configuration->scenarios,
        );
    }

    private function boundedInt(mixed $value, int $maximum): int
    {
        $parsed = $this->optionalInt($value);
        if ($parsed === null) {
            return $maximum;
        }
        if ($parsed < 1) {
            throw new \InvalidArgumentException('steps must be a positive integer');
        }

        return $maximum > 0 ? min($parsed, $maximum) : $parsed;
    }

    private function boundedFloat(mixed $value, float $maximum): float
    {
        if ($value === null || $value === '') {
            return $maximum;
        }
        if (!is_scalar($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('seconds must be numeric');
        }
        $parsed = (float) $value;
        if ($parsed < 0.0) {
            throw new \InvalidArgumentException('seconds cannot be negative');
        }

        return $maximum > 0.0 ? min($parsed, $maximum) : $parsed;
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Expected an integer request parameter');
        }

        return (int) $value;
    }

    /** @return list<string>|null */
    private function commands(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('commands must be a comma-separated string');
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
