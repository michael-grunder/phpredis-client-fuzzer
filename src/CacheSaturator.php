<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Relay\Cluster;
use Relay\Relay;

/**
 * Reads complete values from Relay's known fuzzing key space without SCAN or
 * TYPE probes. The cursor survives bounded batches so successive events keep
 * moving through the key space instead of warming the same first keys.
 */
final class CacheSaturator
{
    /** @var list<array{type: string, command: string}> */
    private const READS = [
        ['type' => Command::STRING, 'command' => 'get'],
        ['type' => Command::LIST, 'command' => 'lrange'],
        ['type' => Command::SET, 'command' => 'smembers'],
        ['type' => Command::HASH, 'command' => 'hgetall'],
        ['type' => Command::ZSET, 'command' => 'zrange'],
    ];

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
        foreach (self::READS as $read) {
            $command = Command::object($read['command']);
            $command->setClientInvoker($clientInvoker);
            $this->commands[$read['command']] = $command;
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
        if ($coordinates > intdiv(PHP_INT_MAX, count(self::READS))) {
            throw new \OverflowException('Configured saturation key space is too large');
        }

        return count(self::READS) * $coordinates;
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
    ): array {
        $initialMode = $this->clientMode($client);
        if ($initialMode !== null && $initialMode !== \Redis::ATOMIC) {
            return ['outcomes' => [], 'caughtDiagnostic' => null];
        }

        $limit = $targetBytes === null
            ? ($maxReads ?? $this->keySpaceSize())
            : $this->keySpaceSize();
        $outcomes = [];
        $caughtDiagnostic = null;

        if ($targetBytes !== null && ($this->memoryUsage)() >= $targetBytes) {
            return ['outcomes' => [], 'caughtDiagnostic' => null];
        }

        for ($readIndex = 0; $readIndex < $limit; $readIndex++) {
            if ($deadlineNanoseconds !== null && hrtime(true) >= $deadlineNanoseconds) {
                break;
            }

            [$command, $key, $arguments] = $this->nextRead();
            $context = 'saturate:' . $command->name();
            Command::setCapturedWarningCommand($context);
            Command::beginInvocation();
            $warningsBefore = Command::capturedWarnings();
            $modeBefore = $this->clientMode($client);
            $started = hrtime(true);
            $replyType = null;
            $replySummary = null;
            $exceptionDetails = null;
            $duration = 0.0;
            $redisErrors = [];
            $warnings = [];
            try {
                $reply = $command->exec($client, ...$arguments);
                $replyType = ValueSummary::type($reply);
                $replySummary = ValueSummary::summarize($reply);
            } catch (\Throwable $throwable) {
                $exceptionDetails = [
                    'class' => $throwable::class,
                    'message' => $throwable->getMessage(),
                    'code' => $throwable->getCode(),
                ];
            } finally {
                $duration = (hrtime(true) - $started) / 1e9;
                $redisErrors = Command::finishInvocationRedisErrors();
                $warnings = $this->warningDifference(
                    $warningsBefore,
                    Command::capturedWarnings(),
                );
                Command::setCapturedWarningCommand(null);
            }

            $outcome = new InvocationOutcome(
                sequence: $sequence,
                command: $context,
                variant: $key,
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

    private static function relayMemoryUsage(): int
    {
        $memory = Relay::stats()['memory'] ?? null;
        $used = is_array($memory) ? ($memory['used'] ?? null) : null;
        if (!is_int($used)) {
            throw new \UnexpectedValueException('Relay stats memory.used is not an integer');
        }

        return $used;
    }

    /** @return array{Command, string, list<mixed>} */
    private function nextRead(): array
    {
        $position = $this->cursor++ % $this->keySpaceSize();
        $read = self::READS[$position % count(self::READS)];
        $position = intdiv($position, count(self::READS));
        $keyIndex = $position % $this->configuration->getMaxKeys();
        $shard = $this->configuration->isCluster()
            ? intdiv($position, $this->configuration->getMaxKeys())
            : 0;
        $key = $this->configuration->getKeyAt($read['type'], $keyIndex, $shard);
        $arguments = [$key];
        if ($read['command'] === 'lrange') {
            $arguments = [$key, 0, -1];
        } elseif ($read['command'] === 'zrange') {
            $arguments = [$key, 0, -1, true];
        }

        return [$this->commands[$read['command']], $key, $arguments];
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
