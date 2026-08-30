<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\ValueSummary;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class TransactionScenario implements StatefulScenario
{
    public function __construct(private readonly string $scenario)
    {
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
        $required = $this->scenario === 'watch-unwatch-discard'
            ? ['watch', 'unwatch', 'multi', 'discard', 'get', 'set', 'getMode']
            : ['multi', 'set', 'get', 'getMode'];
        if ($this->scenario === 'transaction-exec') {
            $required[] = 'exec';
        }
        if ($this->scenario === 'transaction-discard') {
            $required[] = 'discard';
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
        try {
            if ($this->scenario === 'watch-unwatch-discard') {
                $this->call($client, 'watch', [$key], $steps);
                $this->call($client, 'unwatch', [], $steps);
                $this->call($client, 'multi', [], $steps);
                $this->call($client, 'set', [$key, $value], $steps);
                $this->call($client, 'discard', [], $steps);
                $read = $this->call($client, 'get', [$key], $steps);
                $this->postcondition(
                    $postconditions,
                    'unwatch-discard-leaves-key-missing',
                    $read->returned && ($read->replyType === 'false' || $read->replyType === 'null'),
                    'false or null',
                    $read->replyType,
                );
            } elseif ($this->scenario === 'transaction-discard') {
                $this->call($client, 'multi', [], $steps);
                $this->call($client, 'set', [$key, $value], $steps);
                $this->call($client, 'discard', [], $steps);
                $read = $this->call($client, 'get', [$key], $steps);
                $this->postcondition(
                    $postconditions,
                    'discard-leaves-key-missing',
                    $read->returned && ($read->replyType === 'false' || $read->replyType === 'null'),
                    'false or null',
                    $read->replyType,
                );
            } else {
                $this->call($client, 'multi', [], $steps);
                $this->call($client, 'set', [$key, $value], $steps);
                $this->call($client, 'set', [$key2, $value], $steps);
                $this->call($client, 'exec', [], $steps);
                $read1 = $this->call($client, 'get', [$key], $steps);
                $read2 = $this->call($client, 'get', [$key2], $steps);
                $this->postcondition(
                    $postconditions,
                    'exec-commits-first-key',
                    $read1->returned && $read1->reply === ValueSummary::summarize($value),
                    ValueSummary::summarize($value),
                    $read1->reply,
                );
                $this->postcondition(
                    $postconditions,
                    'exec-commits-second-key',
                    $read2->returned && $read2->reply === ValueSummary::summarize($value),
                    ValueSummary::summarize($value),
                    $read2->reply,
                );
            }

            $mode = $this->mode($client);
            $this->postcondition(
                $postconditions,
                'terminal-operation-restores-atomic-mode',
                $mode === Redis::ATOMIC,
                Redis::ATOMIC,
                $mode,
            );
        } catch (\Throwable $throwable) {
            $failure = $throwable::class . ': ' . $throwable->getMessage();
        } finally {
            /* A failed scenario must not poison the following workload. */
            try {
                if ($this->mode($client) !== Redis::ATOMIC) {
                    $client->discard();
                }
            } catch (\Throwable) {
            }
        }

        foreach ($postconditions as $postcondition) {
            if (!$postcondition['passed']) {
                $failure ??= 'Postcondition failed: ' . $postcondition['name'];
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
    ): StatefulObservation {
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
            $reply = $client->{$method}(...$arguments);
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
        if (!$returned && $exception !== null) {
            throw $exception;
        }
        if ($redisErrors !== []) {
            throw new \RuntimeException($redisErrors[0]);
        }

        return $observation;
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
    private function postcondition(
        array &$postconditions,
        string $name,
        bool $passed,
        mixed $expected,
        mixed $observed,
    ): void {
        $postconditions[] = [
            'name' => $name,
            'passed' => $passed,
            'expected' => $expected,
            'observed' => $observed,
        ];
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
