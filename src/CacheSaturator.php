<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Relay\Cluster;
use Relay\Relay;

/**
 * Reads complete values from Relay's known fuzzing key space without SCAN or
 * TYPE probes, optionally seeding each value immediately before its read. The
 * cursor survives bounded batches so successive events keep moving through the
 * key space instead of warming the same first keys.
 *
 * Fast saturation ignores that key space entirely: it grows one deterministic
 * bitmap per step with SETBIT and immediately reads it back, which fills a
 * known number of cache bytes per event instead of however much the generated
 * values happen to be worth.
 */
final class CacheSaturator
{
    /** @var list<array{type: string, read: string, write: string}> */
    private const OPERATIONS = [
        ['type' => Command::STRING, 'read' => 'get', 'write' => 'set'],
        ['type' => Command::LIST, 'read' => 'lrange', 'write' => 'rpush'],
        ['type' => Command::SET, 'read' => 'smembers', 'write' => 'sadd'],
        ['type' => Command::HASH, 'read' => 'hgetall', 'write' => 'hset'],
        ['type' => Command::ZSET, 'read' => 'zrange', 'write' => 'zadd'],
    ];

    /** Commands fast saturation needs beyond the per-type read/write table. */
    private const FAST_COMMANDS = ['setbit', 'get'];

    /** @var array<string, Command> */
    private array $commands = [];

    private int $cursor = 0;

    /** @var \Closure(): int */
    private readonly \Closure $memoryUsage;

    /** @param (\Closure(): int)|null $memoryUsage */
    public function __construct(
        private readonly FuzzConfig $configuration,
        ClientInvoker $clientInvoker,
        ?\Closure $memoryUsage = null,
    ) {
        $this->memoryUsage = $memoryUsage ?? self::relayMemoryUsage(...);
        $names = self::FAST_COMMANDS;
        foreach (self::OPERATIONS as $operation) {
            $names[] = $operation['read'];
            $names[] = $operation['write'];
        }
        foreach (array_unique($names) as $name) {
            $command = Command::object($name);
            $command->setClientInvoker($clientInvoker);
            $this->commands[$name] = $command;
        }
    }

    public function keySpaceSize(): int
    {
        $shards = $this->configuration->isCluster()
            ? $this->configuration->getShards()
            : 1;
        $keys = $this->configuration->getMaxKeys();
        if ($keys > intdiv(PHP_INT_MAX, $shards)) {
            throw new \OverflowException('Configured saturation key space is too large');
        }
        $coordinates = $keys * $shards;
        if ($coordinates > intdiv(PHP_INT_MAX, count(self::OPERATIONS))) {
            throw new \OverflowException('Configured saturation key space is too large');
        }

        return count(self::OPERATIONS) * $coordinates;
    }

    /**
     * @return array{outcomes: list<InvocationOutcome>, caughtDiagnostic: ?string}
     */
    public function run(
        Relay|Cluster $client,
        int $clientIndex,
        int $sequence,
        ?int $maxReads,
        ?int $deadlineNanoseconds,
        ?string $catchPattern,
        ?int $targetBytes = null,
        SaturationMode $mode = SaturationMode::Natural,
    ): array {
        $initialMode = $this->clientMode($client);
        if ($initialMode !== null && $initialMode !== \Redis::ATOMIC) {
            return ['outcomes' => [], 'caughtDiagnostic' => null];
        }

        if ($targetBytes !== null && ($this->memoryUsage)() >= $targetBytes) {
            return ['outcomes' => [], 'caughtDiagnostic' => null];
        }

        if ($mode === SaturationMode::Fast) {
            return $this->runFast(
                $client,
                $clientIndex,
                $sequence,
                $maxReads,
                $deadlineNanoseconds,
                $catchPattern,
                $targetBytes,
            );
        }

        $limit = $targetBytes === null
            ? ($maxReads ?? $this->keySpaceSize())
            : $this->keySpaceSize();
        $outcomes = [];
        $caughtDiagnostic = null;

        for ($readIndex = 0; $readIndex < $limit; $readIndex++) {
            if ($deadlineNanoseconds !== null && hrtime(true) >= $deadlineNanoseconds) {
                break;
            }

            [$operation, $command, $key, $arguments] = $this->nextRead();
            $context = 'saturate:'
                . ($mode === SaturationMode::Seeded ? 'seeded:' : '')
                . $command->name();

            $outcome = $this->record(
                $client,
                $clientIndex,
                $sequence,
                $context,
                $key,
                function () use ($mode, $operation, $client, $key, $command, $arguments): array {
                    $exception = null;
                    if ($mode === SaturationMode::Seeded) {
                        try {
                            $this->commands[$operation['write']]->exec(
                                $client,
                                ...$this->seedArguments($client, $operation['type'], $key),
                            );
                        } catch (\Throwable $throwable) {
                            $exception = $throwable;
                        }
                    }

                    return $this->readReply($command, $client, $arguments, $exception);
                },
            );
            $outcomes[] = $outcome;

            $caughtDiagnostic = $this->matchingDiagnostic($outcome, $catchPattern);
            if ($caughtDiagnostic !== null) {
                break;
            }
            if ($targetBytes !== null && ($this->memoryUsage)() >= $targetBytes) {
                break;
            }
        }

        return ['outcomes' => $outcomes, 'caughtDiagnostic' => $caughtDiagnostic];
    }

    /**
     * Grow one dedicated bitmap per step with SETBIT and read it straight back,
     * so an event adds a predictable number of cache bytes. The step count
     * divides the target, which is what makes the granularity a knob: ten steps
     * against a 10 MiB target write ten 1 MiB keys, a hundred steps write a
     * hundred 100 KiB keys. Keys restart at index zero every event so a run
     * refills the same bounded server-side key space after an eviction instead
     * of growing it without limit, and the chunk size is part of every key name
     * so a pass never inherits a larger string from an earlier run.
     *
     * @return array{outcomes: list<InvocationOutcome>, caughtDiagnostic: ?string}
     */
    private function runFast(
        Relay|Cluster $client,
        int $clientIndex,
        int $sequence,
        ?int $maxReads,
        ?int $deadlineNanoseconds,
        ?string $catchPattern,
        ?int $targetBytes,
    ): array {
        if ($targetBytes === null) {
            throw new \InvalidArgumentException(
                'Fast saturation requires a byte target',
            );
        }

        $steps = max(1, $maxReads ?? 1);
        $chunkBytes = intdiv($targetBytes, $steps) + ($targetBytes % $steps === 0 ? 0 : 1);
        if ($chunkBytes > intdiv(PHP_INT_MAX, 8)) {
            throw new \OverflowException('Fast saturation chunk size is too large');
        }
        $offset = $chunkBytes * 8 - 1;

        $outcomes = [];
        $caughtDiagnostic = null;

        for ($index = 0; $index < $steps; $index++) {
            if ($deadlineNanoseconds !== null && hrtime(true) >= $deadlineNanoseconds) {
                break;
            }

            $key = $this->configuration->getSaturationKeyAt($index, $chunkBytes);
            $outcome = $this->record(
                $client,
                $clientIndex,
                $sequence,
                'saturate:fast:get',
                $key,
                function () use ($client, $key, $offset): array {
                    $exception = null;
                    try {
                        $this->commands['setbit']->exec($client, $key, $offset, true);
                    } catch (\Throwable $throwable) {
                        $exception = $throwable;
                    }

                    return $this->readReply(
                        $this->commands['get'],
                        $client,
                        [$key],
                        $exception,
                    );
                },
            );
            $outcomes[] = $outcome;

            $caughtDiagnostic = $this->matchingDiagnostic($outcome, $catchPattern);
            if ($caughtDiagnostic !== null) {
                break;
            }
            if (($this->memoryUsage)() >= $targetBytes) {
                break;
            }
        }

        return ['outcomes' => $outcomes, 'caughtDiagnostic' => $caughtDiagnostic];
    }

    /**
     * @param list<mixed> $arguments
     * @return array{?string, ?array<string, mixed>, ?\Throwable}
     */
    private function readReply(
        Command $command,
        Relay|Cluster $client,
        array $arguments,
        ?\Throwable $exception,
    ): array {
        try {
            $reply = $command->exec($client, ...$arguments);

            return [ValueSummary::type($reply), ValueSummary::summarize($reply), $exception];
        } catch (\Throwable $throwable) {
            return [null, null, $exception ?? $throwable];
        }
    }

    /**
     * Run one instrumented saturation invocation. The closure owns the calls
     * and returns the read's reply summary plus the first failure it saw, so
     * warnings, Redis errors, and client mode transitions are captured the same
     * way for every saturation mode.
     *
     * @param \Closure(): array{?string, ?array<string, mixed>, ?\Throwable} $invocation
     */
    private function record(
        Relay|Cluster $client,
        int $clientIndex,
        int $sequence,
        string $context,
        string $variant,
        \Closure $invocation,
    ): InvocationOutcome {
        Command::setCapturedWarningCommand($context);
        Command::beginInvocation();
        $warningsBefore = Command::capturedWarnings();
        $modeBefore = $this->clientMode($client);
        $started = hrtime(true);
        $replyType = null;
        $replySummary = null;
        $invocationException = null;
        $exceptionDetails = null;
        $duration = 0.0;
        $redisErrors = [];
        $warnings = [];
        try {
            [$replyType, $replySummary, $invocationException] = $invocation();
        } finally {
            if ($invocationException !== null) {
                $exceptionDetails = [
                    'class' => $invocationException::class,
                    'message' => $invocationException->getMessage(),
                    'code' => $invocationException->getCode(),
                ];
            }
            $duration = (hrtime(true) - $started) / 1e9;
            $redisErrors = Command::finishInvocationRedisErrors();
            $warnings = $this->warningDifference(
                $warningsBefore,
                Command::capturedWarnings(),
            );
            Command::setCapturedWarningCommand(null);
        }

        return new InvocationOutcome(
            sequence: $sequence,
            command: $context,
            variant: $variant,
            clientId: $client::class . '#' . $clientIndex,
            clientIndex: $clientIndex,
            clientClass: $client::class,
            operation: 'saturation',
            replyType: $replyType,
            reply: $replySummary,
            redisErrors: $redisErrors,
            warnings: $warnings,
            exception: $exceptionDetails,
            durationSeconds: $duration,
            modeBefore: $modeBefore,
            modeAfter: $this->clientMode($client),
            slotPolicy: 'same-slot',
        );
    }

    private static function relayMemoryUsage(): int
    {
        $memory = Relay::stats()['memory'] ?? null;
        $used = is_array($memory) ? ($memory['used'] ?? null) : null;
        if (!is_int($used)) {
            throw new \UnexpectedValueException('Relay stats memory.used is not an integer');
        }

        return $used;
    }

    /**
     * @return array{
     *     array{type: string, read: string, write: string},
     *     Command,
     *     string,
     *     list<mixed>
     * }
     */
    private function nextRead(): array
    {
        $position = $this->cursor++ % $this->keySpaceSize();
        $operation = self::OPERATIONS[$position % count(self::OPERATIONS)];
        $position = intdiv($position, count(self::OPERATIONS));
        $keyIndex = $position % $this->configuration->getMaxKeys();
        $shard = $this->configuration->isCluster()
            ? intdiv($position, $this->configuration->getMaxKeys())
            : 0;
        $key = $this->configuration->getKeyAt($operation['type'], $keyIndex, $shard);
        $arguments = [$key];
        if ($operation['read'] === 'lrange') {
            $arguments = [$key, 0, -1];
        } elseif ($operation['read'] === 'zrange') {
            $arguments = [$key, 0, -1, true];
        }

        return [$operation, $this->commands[$operation['read']], $key, $arguments];
    }

    /** @return list<mixed> */
    private function seedArguments(Relay|Cluster $client, string $type, string $key): array
    {
        $arguments = [$key];
        $values = match ($type) {
            Command::STRING => [
                $this->configuration->getRandomValue($client, $type),
            ],
            Command::LIST => $this->configuration->getRandomValues($client, $type),
            Command::SET => $this->configuration->getRandomMembers($type),
            Command::HASH => [$this->randomHash($client, $type)],
            Command::ZSET => $this->randomZset($type),
            default => throw new \LogicException("Unsupported saturation type: {$type}"),
        };
        foreach ($values as $value) {
            $arguments[] = $value;
        }

        return $arguments;
    }

    /** @return array<string, mixed> */
    private function randomHash(Relay|Cluster $client, string $type): array
    {
        $hash = [];
        $members = $this->configuration->randomMemberCount();
        for ($index = 0; $index < $members; $index++) {
            $hash[$this->configuration->getRandomMember($type)] =
                $this->configuration->getRandomValue($client, $type);
        }

        return $hash;
    }

    /** @return list<float|string> */
    private function randomZset(string $type): array
    {
        $arguments = [];
        $members = $this->configuration->randomMemberCount();
        for ($index = 0; $index < $members; $index++) {
            $arguments[] = $this->configuration->getRandomFloat();
            $arguments[] = $this->configuration->getRandomMember($type);
        }

        return $arguments;
    }

    private function matchingDiagnostic(InvocationOutcome $outcome, ?string $pattern): ?string
    {
        if ($pattern === null) {
            return null;
        }
        if ($outcome->exception !== null) {
            $exception = $outcome->exception['class'] . ': ' . $outcome->exception['message'];
            if (stripos($exception, $pattern) !== false) {
                return $exception;
            }
        }
        foreach ($outcome->redisErrors as $error) {
            $diagnostic = 'Redis error: ' . $error;
            if (stripos($diagnostic, $pattern) !== false) {
                return $diagnostic;
            }
        }
        foreach (array_keys($outcome->warnings) as $warning) {
            if (stripos($warning, $pattern) !== false) {
                return $warning;
            }
        }

        return null;
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

    private function clientMode(Relay|Cluster $client): ?int
    {
        try {
            return $client->getMode(true);
        } catch (\Throwable) {
            return null;
        }
    }
}
