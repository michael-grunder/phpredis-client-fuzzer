<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester;

use Mgrunder\RedisClientTester\Commands\Command;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class DifferentialOracle
{
    /**
     * These commands can legitimately return different values for identical
     * calls, or expose cache metadata that is expected to differ between the
     * reference and subject. They need command-specific comparators before
     * they can participate in the oracle.
     */
    private const NON_COMPARABLE_COMMANDS = [
        'getwithmeta',
        'hgetwithmeta',
        'hrandfield',
        'srandmember',
    ];

    /** @var list<DifferentialOutcome> */
    private array $outcomes = [];

    private int $sequence = 0;
    private string $command = '';
    private string $operation = '';
    private bool $observed = false;
    private ?DifferentialObservation $referenceObservation = null;

    /** @var list<array<string, mixed>> */
    private array $argumentSummaries = [];

    /**
     * @param Redis|RedisCluster|Relay|Cluster $reference
     * @param Relay|Cluster $subject
     */
    public function __construct(
        private readonly Redis|RedisCluster|Relay|Cluster $reference,
        private readonly Relay|Cluster $subject,
        private readonly int $referenceIndex,
        private readonly int $subjectIndex,
        private readonly float $toleranceMilliseconds,
        private readonly float $pollIntervalMilliseconds,
        private readonly ClientInvoker $clientInvoker = new StrictClientInvoker(),
    ) {
    }

    public function subject(): Relay|Cluster
    {
        return $this->subject;
    }

    public function beginStep(int $sequence, string $command, string $operation): void
    {
        $this->sequence = $sequence;
        $this->command = $command;
        $this->operation = $operation;
        $this->observed = false;
        $this->referenceObservation = null;
        $this->argumentSummaries = [];
    }

    public function finishStep(): void
    {
        $this->referenceObservation = null;
        $this->argumentSummaries = [];
    }

    /**
     * Execute the reference side before the scheduled Relay call.
     *
     * @param list<mixed> $arguments
     */
    public function prepare(
        Command $command,
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
    ): bool {
        if ($this->observed
            || $client !== $this->subject
            || $this->operation !== 'normal'
            || $method === 'rawCommand'
            || !method_exists($this->reference, $method)
            || in_array($command->name(), self::NON_COMPARABLE_COMMANDS, true)
            || ($command->flags() & Command::CACHED) === 0
            || ($command->flags() & Command::READ) === 0
            || !$this->isAtomic($this->subject)
            || !$this->isAtomic($this->reference)) {
            return false;
        }

        $this->observed = true;
        $this->clearRedisError($this->subject, 'setup');
        $this->argumentSummaries = array_map(
            static fn (mixed $argument): array => ValueSummary::summarize($argument),
            $arguments,
        );
        $this->referenceObservation = $this->invoke($this->reference, $method, $arguments, 'reference-read');

        return true;
    }

    /**
     * @param list<mixed> $arguments
     * @param list<string> $redisErrors
     * @param array<string, int> $warnings
     */
    public function complete(
        string $method,
        array $arguments,
        bool $returned,
        mixed $reply,
        array $redisErrors,
        array $warnings,
        ?\Throwable $exception,
        float $durationSeconds,
    ): void {
        $reference = $this->referenceObservation;
        if ($reference === null) {
            return;
        }

        $initial = DifferentialObservation::fromCall(
            $returned,
            $reply,
            $redisErrors,
            $warnings,
            $exception,
            $durationSeconds,
        );
        $initialDifferences = $reference->differences($initial);
        $convergenceStarted = hrtime(true);
        $final = $this->invoke($this->subject, $method, $arguments, 'warm-read');
        $attempts = 2;
        $finalDifferences = $reference->differences($final);

        $deadline = $convergenceStarted + (int) round($this->toleranceMilliseconds * 1e6);
        while ($finalDifferences !== [] && hrtime(true) < $deadline) {
            $remainingNanoseconds = $deadline - hrtime(true);
            $sleepMicroseconds = min(
                max(1, (int) round($this->pollIntervalMilliseconds * 1000)),
                max(1, (int) ceil($remainingNanoseconds / 1000)),
            );
            InvocationRecorder::sleep($sleepMicroseconds, 'convergence-poll', $this->context());
            $final = $this->invoke($this->subject, $method, $arguments, 'convergence-poll');
            $attempts++;
            $finalDifferences = $reference->differences($final);
        }

        if ($initialDifferences === [] && $finalDifferences === [] && $attempts === 2) {
            $status = 'matched';
            $convergenceSeconds = null;
        } elseif ($finalDifferences === []) {
            $status = 'converged';
            $convergenceSeconds = (hrtime(true) - $convergenceStarted) / 1e9;
        } else {
            $status = 'divergent';
            $convergenceSeconds = null;
        }

        $this->outcomes[] = new DifferentialOutcome(
            sequence: $this->sequence,
            command: $this->command,
            operation: $this->operation,
            arguments: $this->argumentSummaries,
            referenceClientId: $this->reference::class . '#' . $this->referenceIndex,
            subjectClientId: $this->subject::class . '#' . $this->subjectIndex,
            status: $status,
            initialMatch: $initialDifferences === [],
            initialDifferences: $initialDifferences,
            finalDifferences: $finalDifferences,
            subjectAttempts: $attempts,
            convergenceSeconds: $convergenceSeconds,
            toleranceMilliseconds: $this->toleranceMilliseconds,
            reference: $reference,
            subjectInitial: $initial,
            subjectFinal: $final,
        );
        $this->referenceObservation = null;
    }

    /** @return list<DifferentialOutcome> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function hasDivergence(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->status === 'divergent') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Redis|RedisCluster|Relay|Cluster $client
     * @param list<mixed> $arguments
     */
    private function invoke(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
        string $phase,
    ): DifferentialObservation {
        /* getLastError() is sticky on both clients. Only attribute an error
         * produced by this call to this observation. */
        $this->clearRedisError($client, $phase);
        $warningsBefore = Command::capturedWarnings();
        $started = hrtime(true);
        $returned = false;
        $reply = null;
        $exception = null;
        try {
            $reply = InvocationRecorder::invoke(
                $this->clientInvoker, $client, $method, $arguments, $phase, $this->context($client),
            );
            $returned = true;
        } catch (\Throwable $throwable) {
            $exception = $throwable;
        }

        $redisErrors = [];
        try {
            $redisError = $this->diagnostic($client, 'getLastError', [], $phase);
            if (is_string($redisError) && $redisError !== '') {
                $redisErrors[] = $redisError;
                $this->diagnostic($client, 'clearLastError', [], $phase);
            }
        } catch (\Throwable $diagnosticFailure) {
            $exception ??= $diagnosticFailure;
        }

        return DifferentialObservation::fromCall(
            $returned,
            $reply,
            $redisErrors,
            $this->warningDifference($warningsBefore, Command::capturedWarnings()),
            $exception,
            (hrtime(true) - $started) / 1e9,
        );
    }

    private function clearRedisError(Redis|RedisCluster|Relay|Cluster $client, string $phase): void
    {
        try {
            $this->diagnostic($client, 'clearLastError', [], $phase);
        } catch (\Throwable) {
        }
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

    private function context(Redis|RedisCluster|Relay|Cluster|null $client = null): string
    {
        $identity = $client === null ? '' : ' client_id=' . $client::class . '#'
            . ($client === $this->reference ? $this->referenceIndex : $this->subjectIndex);

        return "oracle sequence={$this->sequence} command={$this->command}"
            . " tolerance_ms={$this->toleranceMilliseconds} poll_interval_ms={$this->pollIntervalMilliseconds}"
            . $identity;
    }

    /** @param list<mixed> $arguments */
    private function diagnostic(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
        string $phase,
    ): mixed {
        return InvocationRecorder::invoke(new StrictClientInvoker(), $client, $method, $arguments,
            $phase, $this->context($client) . ' diagnostic');
    }

    private function isAtomic(Redis|RedisCluster|Relay|Cluster $client): bool
    {
        try {
            $mode = $this->diagnostic($client, 'getMode',
                $client instanceof Relay || $client instanceof Cluster ? [true] : [], 'setup');

            return $mode === Redis::ATOMIC;
        } catch (\Throwable) {
            return false;
        }
    }
}
