<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\Commands\SlotPolicy;
use Mgrunder\PhpredisCommandFuzzer\Coverage\ServerCommands;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class Fuzzer
{
    /**
     * @param list<object> $clients
     */
    public function run(array $clients, RunConfiguration $configuration = new RunConfiguration()): FuzzResult
    {
        $clients = $this->normalizeClients($clients);
        $serverCommands = $this->serverCommands($clients);

        $seed = $configuration->seed ?? random_int(0, PHP_INT_MAX);
        mt_srand($seed);

        $registry = (new CommandFilter())->apply(new Registry(), $configuration);
        $registry->filter(
            fn (Command $command): bool => $this->eligibleClients($clients, $command, $configuration->raw) !== [],
        );
        if (count($registry) === 0) {
            throw new \UnderflowException('None of the selected commands are supported by the supplied clients');
        }
        $weight = 0.0;
        foreach ($registry as $command) {
            $weight += $command->weight();
        }
        if ($weight <= 0.0) {
            throw new \UnderflowException('Supported commands must have a positive total weight');
        }
        $selector = new AliasTable($registry->commands());
        $arguments = $this->argumentConfiguration($clients, $configuration);

        $started = hrtime(true);
        $steps = 0;
        $crossSlotSteps = 0;
        /** @var array<string, array{count: int, replies: array<string, int>, exceptions: array<string, int>}> $results */
        $results = [];
        /** @var array<string, array<int, true>> $falseReplyClients */
        $falseReplyClients = [];
        /** @var list<InvocationOutcome> $outcomes */
        $outcomes = [];
        /** @var array<int, int> $clientIndexes */
        $clientIndexes = [];
        foreach ($clients as $index => $runClient) {
            $clientIndexes[spl_object_id($runClient)] ??= $index;
        }

        $scriptLogging = $configuration->scriptLog !== null;
        if ($scriptLogging) {
            ScriptLogger::init($configuration->scriptLog, [
                'seed' => $seed,
                'commands' => implode(',', $registry->names()),
                'client-count' => count($clients),
                'client-classes' => implode(',', array_map(static fn (object $client): string => $client::class, $clients)),
                'php-version' => PHP_VERSION,
                'phpredis-version' => phpversion('redis') ?: 'not-loaded',
                'relay-version' => phpversion('relay') ?: 'not-loaded',
            ], $clients);
        }

        Command::resetCapturedWarnings();
        $warnings = [];
        $commandWarnings = [];
        $caughtDiagnostic = null;
        try {
            while ($this->withinLimits($steps, $started, $configuration)) {
                $command = $selector->pick();
                $eligible = $this->eligibleClients($clients, $command, $configuration->raw);

                $client = $eligible[array_rand($eligible)];
                $operations = [];
                if ($command instanceof FuzzInterface && method_exists($client, $command->name())) {
                    $operations[] = 'fuzz';
                }
                if ($configuration->raw && $command instanceof FuzzRawInterface) {
                    $operations[] = 'raw';
                }
                if ($operations === []) {
                    throw new \LogicException('An eligible command has no executable operation');
                }

                /* Decide how this command's generated keys map onto cluster
                   hash slots before it builds any arguments. */
                $slotPolicy = $arguments->beginCommand($command);
                if ($slotPolicy === SlotPolicy::CrossSlot) {
                    $crossSlotSteps++;
                }

                $name = $command->name();
                $results[$name] ??= ['count' => 0, 'replies' => [], 'exceptions' => []];
                $results[$name]['count']++;
                $steps++;

                Command::setCapturedWarningCommand($name);
                Command::beginInvocation();
                $warningsBefore = Command::capturedWarnings();
                $modeBefore = $this->clientMode($client);
                $invocationStarted = hrtime(true);
                $operation = $operations[array_rand($operations)];
                $replyType = null;
                $replySummary = null;
                $exceptionDetails = null;
                $redisErrors = [];
                $invocationWarnings = [];
                try {
                    if ($operation === 'raw' && $command instanceof FuzzRawInterface) {
                        $reply = $command->fuzzRaw($client, $arguments);
                    } elseif ($command instanceof FuzzInterface) {
                        $reply = $command->fuzz($client, $arguments);
                    } else {
                        throw new \LogicException('Selected operation is not implemented by the command');
                    }
                    $replyType = $this->replyType($reply);
                    $replySummary = $this->summarizeReply($reply);
                    $results[$name]['replies'][$replyType] = ($results[$name]['replies'][$replyType] ?? 0) + 1;
                    if ($reply === false) {
                        $falseReplyClients[$name][spl_object_id($client)] = true;
                    }
                } catch (\Throwable $throwable) {
                    $exception = $throwable::class . ': ' . $throwable->getMessage();
                    $results[$name]['exceptions'][$exception] = ($results[$name]['exceptions'][$exception] ?? 0) + 1;
                    $exceptionDetails = [
                        'class' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'code' => $throwable->getCode(),
                    ];
                    if ($this->matchesCatch($exception, $configuration->catchPattern)) {
                        $caughtDiagnostic = $exception;
                    }
                } finally {
                    $duration = (hrtime(true) - $invocationStarted) / 1e9;
                    $redisErrors = Command::finishInvocationRedisErrors();
                    $invocationWarnings = $this->warningDifference(
                        $warningsBefore,
                        Command::capturedWarnings(),
                    );
                    $clientIndex = $clientIndexes[spl_object_id($client)];
                    $outcomes[] = new InvocationOutcome(
                        sequence: $steps,
                        command: $name,
                        variant: null,
                        clientId: $client::class . '#' . $clientIndex,
                        clientIndex: $clientIndex,
                        clientClass: $client::class,
                        operation: $operation === 'raw' ? 'raw' : 'normal',
                        replyType: $replyType,
                        reply: $replySummary,
                        redisErrors: $redisErrors,
                        warnings: $invocationWarnings,
                        exception: $exceptionDetails,
                        durationSeconds: $duration,
                        modeBefore: $modeBefore,
                        modeAfter: $this->clientMode($client),
                        slotPolicy: $slotPolicy->value,
                    );
                    Command::setCapturedWarningCommand(null);
                }

                if ($caughtDiagnostic === null) {
                    foreach ($redisErrors as $redisError) {
                        $diagnostic = 'Redis error: ' . $redisError;
                        if ($this->matchesCatch($diagnostic, $configuration->catchPattern)) {
                            $caughtDiagnostic = $diagnostic;
                            break;
                        }
                    }
                }
                if ($caughtDiagnostic === null && $configuration->catchPattern !== null) {
                    foreach (array_keys($invocationWarnings) as $warning) {
                        if ($this->matchesCatch($warning, $configuration->catchPattern)) {
                            $caughtDiagnostic = $warning;
                            break;
                        }
                    }
                }
                if ($caughtDiagnostic !== null) {
                    break;
                }
            }
        } finally {
            $warnings = Command::finishCapturedWarnings();
            $commandWarnings = Command::capturedWarningsByCommand();
            if ($scriptLogging) {
                ScriptLogger::finish();
            }
        }

        $elapsed = (hrtime(true) - $started) / 1e9;

        return new FuzzResult(
            $seed,
            $steps,
            $elapsed,
            $results,
            $warnings,
            array_values($registry->names()),
            $this->environment($clients),
            $configuration->jsonSerialize(),
            $crossSlotSteps,
            $commandWarnings,
            $caughtDiagnostic,
            (new ProblematicCommandDetector())->detect(
                $results,
                $falseReplyClients,
                $serverCommands,
                $registry,
            ),
            $outcomes,
        );
    }

    /**
     * Read server capabilities without using them to filter the workload. A
     * failed lookup leaves that client's false-only commands unclassified
     * rather than risking a false positive.
     *
     * @param non-empty-list<Redis|RedisCluster|Relay|Cluster> $clients
     * @return array<int, ServerCommands|null>
     */
    private function serverCommands(array $clients): array
    {
        $commands = [];
        foreach ($clients as $client) {
            try {
                $commands[spl_object_id($client)] = ServerCommands::fromClient($client);
            } catch (\Throwable) {
                $commands[spl_object_id($client)] = null;
            }
        }

        return $commands;
    }

    private function matchesCatch(string $diagnostic, ?string $search): bool
    {
        return $search !== null && stripos($diagnostic, $search) !== false;
    }

    /**
     * @param non-empty-list<Redis|RedisCluster|Relay|Cluster> $clients
     */
    private function argumentConfiguration(array $clients, RunConfiguration $configuration): FuzzConfig
    {
        $arguments = new FuzzConfig($clients[0]);
        $cluster = false;
        foreach ($clients as $client) {
            $cluster = $cluster || $client instanceof RedisCluster || $client instanceof Cluster;
        }

        return $arguments
            ->setCluster($cluster)
            ->setKeys($configuration->keys)
            ->setShards($configuration->shards)
            ->setMembers($configuration->members)
            ->setMinLen($configuration->minLength)
            ->setMaxLen($configuration->maxLength)
            ->setMaxKeys($configuration->maxKeysPerCommand)
            ->setMaxPrefixLen($configuration->maxPrefixLength)
            ->setWrongtype($configuration->wrongTypeChance)
            ->setCrossSlot($configuration->crossSlotChance);
    }

    private function withinLimits(int $steps, int $started, RunConfiguration $configuration): bool
    {
        if ($configuration->maxSteps > 0 && $steps >= $configuration->maxSteps) {
            return false;
        }

        return $configuration->maxSeconds <= 0.0
            || (hrtime(true) - $started) / 1e9 < $configuration->maxSeconds;
    }

    /**
     * @param non-empty-list<Redis|RedisCluster|Relay|Cluster> $clients
     * @return list<Redis|RedisCluster|Relay|Cluster>
     */
    private function eligibleClients(array $clients, Command $command, bool $raw): array
    {
        return array_values(array_filter(
            $clients,
            static fn (Redis|RedisCluster|Relay|Cluster $client): bool =>
                ($command instanceof FuzzInterface && method_exists($client, $command->name()))
                || ($raw && $command instanceof FuzzRawInterface),
        ));
    }

    private function replyType(mixed $reply): string
    {
        return match (true) {
            $reply === null => 'null',
            is_bool($reply) => $reply ? 'true' : 'false',
            is_int($reply) => 'integer',
            is_float($reply) => 'float',
            is_string($reply) => 'string',
            is_array($reply) => 'array',
            default => get_debug_type($reply),
        };
    }

    /**
     * Keep reports JSON-safe and bounded even when a client returns binary or
     * deeply nested data.
     *
     * @return array<string, mixed>
     */
    private function summarizeReply(mixed $reply, int $depth = 0): array
    {
        if ($reply === null || is_bool($reply) || is_int($reply)) {
            return ['type' => get_debug_type($reply), 'value' => $reply];
        }
        if (is_float($reply)) {
            return [
                'type' => 'float',
                'value' => is_finite($reply) ? $reply : (string) $reply,
            ];
        }
        if (is_string($reply)) {
            $previewLength = 96;

            return [
                'type' => 'string',
                'length' => strlen($reply),
                'preview_base64' => base64_encode(substr($reply, 0, $previewLength)),
                'truncated' => strlen($reply) > $previewLength,
                'sha256' => hash('sha256', $reply),
            ];
        }
        if (is_array($reply)) {
            $summary = [
                'type' => 'array',
                'count' => count($reply),
                'truncated' => count($reply) > 16 || $depth >= 3,
                'entries' => [],
            ];
            if ($depth >= 3) {
                return $summary;
            }
            $index = 0;
            foreach ($reply as $key => $value) {
                if ($index++ >= 16) {
                    break;
                }
                $summary['entries'][] = [
                    'key' => $key,
                    'value' => $this->summarizeReply($value, $depth + 1),
                ];
            }

            return $summary;
        }
        if (is_object($reply)) {
            return ['type' => 'object', 'class' => $reply::class];
        }
        if (is_resource($reply)) {
            return ['type' => 'resource', 'resource_type' => get_resource_type($reply)];
        }

        return ['type' => get_debug_type($reply)];
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return array<string, int>
     */
    private function warningDifference(array $before, array $after): array
    {
        $difference = [];
        foreach ($after as $warning => $count) {
            $added = $count - ($before[$warning] ?? 0);
            if ($added > 0) {
                $difference[$warning] = $added;
            }
        }

        return $difference;
    }

    private function clientMode(Redis|RedisCluster|Relay|Cluster $client): ?int
    {
        try {
            $mode = $client instanceof Relay || $client instanceof Cluster
                ? $client->getMode(true)
                : $client->getMode();

            return $mode;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param non-empty-list<Redis|RedisCluster|Relay|Cluster> $clients
     * @return array{php_version: string, phpredis_version: string|false, relay_version: string|false, clients: list<array<string, mixed>>}
     */
    private function environment(array $clients): array
    {
        return [
            'php_version' => PHP_VERSION,
            'phpredis_version' => phpversion('redis'),
            'relay_version' => phpversion('relay'),
            'clients' => array_map(fn (object $client): array => $this->clientDetails($client), $clients),
        ];
    }

    /** @return array<string, mixed> */
    private function clientDetails(Redis|RedisCluster|Relay|Cluster $client): array
    {
        $details = ['class' => $client::class];
        try {
            if ($client instanceof Redis || $client instanceof Relay) {
                $details['topology'] = 'standalone';
                $details['server'] = ['host' => $client->getHost(), 'port' => $client->getPort()];
            } else {
                $details['topology'] = 'cluster';
                $details['servers'] = $client->_masters();
            }
            $details['serializer'] = $client->getOption(Redis::OPT_SERIALIZER);
            $details['compression'] = $client->getOption(Redis::OPT_COMPRESSION);
            $details['prefix'] = $client->getOption(Redis::OPT_PREFIX);
            if ($client instanceof Cluster) {
                $details['relay_cluster'] = RelayClusterOptions::describe($client);
            }
        } catch (\Throwable $throwable) {
            $details['introspection_error'] = $throwable->getMessage();
        }

        return $details;
    }

    /**
     * @param list<object> $clients
     * @return non-empty-list<Redis|RedisCluster|Relay|Cluster>
     */
    private function normalizeClients(array $clients): array
    {
        if ($clients === []) {
            throw new \InvalidArgumentException('At least one Redis client is required');
        }

        $normalized = [];
        foreach ($clients as $client) {
            if (!$client instanceof Redis
                && !$client instanceof RedisCluster
                && !$client instanceof Relay
                && !$client instanceof Cluster) {
                throw new \InvalidArgumentException('Unsupported client: ' . get_debug_type($client));
            }
            $normalized[] = $client;
        }

        return $normalized;
    }
}
