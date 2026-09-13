<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\ValueSummary;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class TransactionScenario implements StatefulScenario
{
    public function __construct(
        private readonly string $scenario,
        private readonly ClientInvoker $clientInvoker = new StrictClientInvoker(),
    ) {
    }

    public function name(): string
    {
        return $this->scenario;
    }

    public function run(
        Redis|RedisCluster|Relay|Cluster $client,
        int $seed,
        int $clientIndex,
    ): StatefulOutcome {
        $clientId = $client::class . '#' . $clientIndex;
        $required = ['multi', 'discard', 'set', 'get', 'getMode'];
        if ($this->scenario === 'watch-unwatch-discard') {
            $required[] = 'watch';
            $required[] = 'unwatch';
        } elseif ($this->scenario === 'transaction-exec') {
            $required[] = 'exec';
        }

        foreach ($required as $method) {
            if (!method_exists($client, $method)) {
                return new StatefulOutcome(
                    scenario: $this->scenario,
                    status: 'skipped',
                    clientId: $clientId,
                    steps: [],
                    postconditions: [],
                    failure: "Client does not expose {$method}()",
                );
            }
        }

        $steps = [];
        $postconditions = [];
        $failure = null;
        $nextClientIndex = $clientIndex + 1;
        $key = 'fuzzer:state:' . $seed . ':' . $this->scenario
            . ':{' . $clientIndex . '}:value';
        $key2 = 'fuzzer:state:' . $seed . ':' . $this->scenario
            . ':{' . $nextClientIndex . '}:value';
        $value = "state-{$seed}";
        $initial = "initial-{$seed}";
        $keys = $this->scenario === 'transaction-exec' ? [$key, $key2] : [$key];
        $ownsState = false;
        $watchMayBeActive = false;
        try {
            $this->expect($postconditions, 'starts-in-atomic-mode', Redis::ATOMIC, $this->mode($client));
            $ownsState = true;

            // Reinitialize even when the same seed and client are reused.
            foreach ($keys as $index => $scenarioKey) {
                $this->expect(
                    $postconditions,
                    "setup-set-{$index}-succeeds",
                    true,
                    $this->call($client, 'set', [$scenarioKey, $initial], $steps),
                );
                $this->expect(
                    $postconditions,
                    "setup-key-{$index}-has-initial-value",
                    $initial,
                    $this->call($client, 'get', [$scenarioKey], $steps),
                );
            }

            if ($this->scenario === 'watch-unwatch-discard') {
                $watchMayBeActive = true;
                $this->expect($postconditions, 'watch-succeeds', true, $this->call($client, 'watch', [$key], $steps));
                $this->expect($postconditions, 'unwatch-succeeds', true, $this->call($client, 'unwatch', [], $steps));
                $watchMayBeActive = false;
            }

            $this->expect($postconditions, 'multi-acknowledges-entry', $client, $this->call($client, 'multi', [], $steps));
            $this->expect($postconditions, 'multi-enters-multi-mode', Redis::MULTI, $this->mode($client));
            foreach ($keys as $index => $scenarioKey) {
                $this->expect(
                    $postconditions,
                    "set-{$index}-is-queued",
                    $client,
                    $this->call($client, 'set', [$scenarioKey, $value], $steps),
                );
                $this->expect($postconditions, "set-{$index}-preserves-multi-mode", Redis::MULTI, $this->mode($client));
            }

            if ($this->scenario === 'transaction-exec') {
                $this->expect($postconditions, 'exec-returns-two-successful-replies', [true, true], $this->call($client, 'exec', [], $steps));
            } else {
                $this->expect($postconditions, 'discard-succeeds', true, $this->call($client, 'discard', [], $steps));
            }
            $this->expect($postconditions, 'terminal-operation-restores-atomic-mode', Redis::ATOMIC, $this->mode($client));

            foreach ($keys as $index => $scenarioKey) {
                $this->expect(
                    $postconditions,
                    $this->scenario === 'transaction-exec' ? "exec-commits-key-{$index}" : 'discard-preserves-initial-value',
                    $this->scenario === 'transaction-exec' ? $value : $initial,
                    $this->call($client, 'get', [$scenarioKey], $steps),
                );
            }
            $this->expect($postconditions, 'final-reads-preserve-atomic-mode', Redis::ATOMIC, $this->mode($client));
        } catch (\Throwable $throwable) {
            $failure = $throwable::class . ': ' . $throwable->getMessage();
        } finally {
            // Do not discard a transaction that belonged to the embedded caller.
            if ($ownsState) {
                try {
                    if ($this->mode($client) !== Redis::ATOMIC) {
                        $this->expect($postconditions, 'cleanup-discard-succeeds', true, $this->call($client, 'discard', [], $steps));
                    }
                    if ($watchMayBeActive) {
                        $this->expect($postconditions, 'cleanup-unwatch-succeeds', true, $this->call($client, 'unwatch', [], $steps));
                    }
                    $this->expect($postconditions, 'cleanup-restores-atomic-mode', Redis::ATOMIC, $this->mode($client));
                } catch (\Throwable $throwable) {
                    $failure = ($failure === null ? '' : $failure . '; ')
                        . 'Cleanup failed: ' . $throwable::class . ': ' . $throwable->getMessage();
                }
            }
        }

        return new StatefulOutcome(
            scenario: $this->scenario,
            status: $failure === null ? 'passed' : 'failed',
            clientId: $clientId,
            steps: $steps,
            postconditions: $postconditions,
            failure: $failure,
        );
    }

    /**
     * @param list<mixed> $arguments
     * @param list<StatefulObservation> $steps
     */
    private function call(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
        array &$steps,
    ): mixed {
        $warningsBefore = Command::capturedWarnings();
        $modeBefore = $this->mode($client);
        $started = hrtime(true);
        $returned = false;
        $reply = null;
        $exception = null;
        $redisErrors = [];
        try {
            try {
                $client->clearLastError();
            } catch (\Throwable) {
            }
            $reply = $this->clientInvoker->invoke($client, $method, $arguments);
            $returned = true;
        } catch (\Throwable $throwable) {
            $exception = $throwable;
        }
        try {
            $error = $client->getLastError();
            if (is_string($error) && $error !== '') {
                $redisErrors[] = $error;
                $client->clearLastError();
            }
        } catch (\Throwable $diagnosticFailure) {
            $exception ??= $diagnosticFailure;
        }
        $observation = StatefulObservation::fromCall(
            operation: $method,
            returned: $returned,
            reply: $reply,
            redisErrors: $redisErrors,
            warnings: $this->warningDifference($warningsBefore, Command::capturedWarnings()),
            exception: $exception,
            durationSeconds: (hrtime(true) - $started) / 1e9,
            modeBefore: $modeBefore,
            modeAfter: $this->mode($client),
        );
        $steps[] = $observation;
        if ($exception !== null) {
            throw $exception;
        }
        if ($redisErrors !== []) {
            throw new \RuntimeException($redisErrors[0]);
        }

        return $reply;
    }

    private function mode(Redis|RedisCluster|Relay|Cluster $client): ?int
    {
        try {
            return $client instanceof Relay || $client instanceof Cluster
                ? $client->getMode(true)
                : $client->getMode();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<array{name: string, passed: bool, expected: mixed, observed: mixed}> $postconditions */
    private function expect(
        array &$postconditions,
        string $name,
        mixed $expected,
        mixed $observed,
    ): void {
        $passed = $expected === $observed;
        $postconditions[] = [
            'name' => $name,
            'passed' => $passed,
            'expected' => ValueSummary::summarize($expected),
            'observed' => ValueSummary::summarize($observed),
        ];
        if (!$passed) {
            throw new \RuntimeException('Postcondition failed: ' . $name);
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
}
