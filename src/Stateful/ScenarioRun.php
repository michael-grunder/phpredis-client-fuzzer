<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\InvocationRecorder;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Values\ValueCode;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\ValueSummary;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Shared execution surface for stateful scenarios.
 *
 * Every client call a scenario makes goes through this object so that the
 * operation, its reply, Redis errors, PHP warnings, thrown exceptions and the
 * client mode before and after are recorded in order. Postconditions are named
 * and recorded whether they pass or fail; a failed one aborts the scenario.
 */
final class ScenarioRun
{
    /** @var list<StatefulObservation> */
    private array $steps = [];

    /** @var list<array{name: string, passed: bool, expected: mixed, observed: mixed}> */
    private array $postconditions = [];

    private bool $watchActive = false;
    private string $phase = 'setup';

    public function __construct(
        private readonly Redis|RedisCluster|Relay|Cluster $client,
        private readonly ClientInvoker $clientInvoker,
        private readonly string $scenario,
        private readonly int $seed,
        private readonly int $clientIndex,
    ) {
    }

    public function client(): Redis|RedisCluster|Relay|Cluster
    {
        return $this->client;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * Scenario-owned key name. `$tagOffset` selects the cluster hash tag, so
     * keys that must share a slot use the same offset and deliberately
     * cross-slot keys use different ones.
     */
    public function key(string $name, int $tagOffset = 0): string
    {
        return 'fuzzer:state:' . $this->seed . ':' . $this->scenario
            . ':{' . ($this->clientIndex + $tagOffset) . '}:' . $name;
    }

    /** Seed-derived value, so a rerun of the same seed writes the same bytes. */
    public function value(string $name): string
    {
        return $name . '-' . $this->seed;
    }

    /**
     * Invoke the client and require an ordinary reply: a Redis error or a
     * thrown exception aborts the scenario.
     *
     * @param list<mixed> $arguments
     */
    public function call(string $method, array $arguments = [], ?string $label = null): mixed
    {
        $call = $this->attempt($method, $arguments, $label);
        if ($call->exception !== null) {
            throw $call->exception;
        }
        if ($call->redisErrors !== []) {
            throw new \RuntimeException($call->redisErrors[0]);
        }

        return $call->reply;
    }

    /**
     * Invoke the client and record what happened without judging it. Used for
     * steps whose whole point is an error, an abort, or a client difference.
     *
     * @param list<mixed> $arguments
     */
    public function attempt(string $method, array $arguments = [], ?string $label = null): ScenarioCall
    {
        return $this->attemptOn($this->client, $method, $arguments, $label);
    }

    /**
     * Invoke a second connection (a scenario peer). Its operations are
     * recorded in the same step list, labelled so the sequence stays readable.
     *
     * @param list<mixed> $arguments
     */
    public function callPeer(
        Redis|RedisCluster|Relay|Cluster $peer,
        string $method,
        array $arguments = [],
    ): mixed {
        $call = $this->attemptOn($peer, $method, $arguments, 'peer:' . $method);
        if ($call->exception !== null) {
            throw $call->exception;
        }
        if ($call->redisErrors !== []) {
            throw new \RuntimeException($call->redisErrors[0]);
        }

        return $call->reply;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function attemptOn(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments = [],
        ?string $label = null,
    ): ScenarioCall {
        $operation = $label ?? $method;
        $argumentCode = array_map(ValueCode::export(...), $arguments);
        $warningsBefore = Command::capturedWarnings();
        $modeBefore = $this->mode($client);
        $started = hrtime(true);
        $returned = false;
        $reply = null;
        $exception = null;
        $redisErrors = [];
        try {
            try {
                $this->diagnostic($client, 'clearLastError');
            } catch (\Throwable) {
                /* Not every client exposes a usable error slot; ignore it. */
            }
            $reply = InvocationRecorder::invoke(
                $this->clientInvoker, $client, $method, $arguments,
                $this->phase, $this->context($client) . ' operation=' . $operation,
            );
            $returned = true;
        } catch (\Throwable $throwable) {
            $exception = $throwable;
        }
        try {
            $error = $this->diagnostic($client, 'getLastError');
            if (is_string($error) && $error !== '') {
                $redisErrors[] = $error;
                $this->diagnostic($client, 'clearLastError');
            }
        } catch (\Throwable $diagnosticFailure) {
            $exception ??= $diagnosticFailure;
        }

        $this->steps[] = StatefulObservation::fromCall(
            operation: $operation,
            returned: $returned,
            reply: $reply,
            redisErrors: $redisErrors,
            warnings: $this->warningDifference($warningsBefore, Command::capturedWarnings()),
            exception: $exception,
            durationSeconds: (hrtime(true) - $started) / 1e9,
            modeBefore: $modeBefore,
            modeAfter: $this->mode($client),
            phase: $this->phase,
            clientId: $this->identity($client),
            argumentCode: $argumentCode,
        );

        return new ScenarioCall(
            operation: $operation,
            returned: $returned,
            reply: $reply,
            redisErrors: $redisErrors,
            exception: $exception,
        );
    }

    public function mode(Redis|RedisCluster|Relay|Cluster|null $client = null): ?int
    {
        $client ??= $this->client;
        try {
            $mode = $this->diagnostic($client, 'getMode',
                $client instanceof Relay || $client instanceof Cluster ? [true] : []);

            return is_int($mode) ? $mode : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @internal Mark setup, scheduled-call or cleanup before invoking it. */
    public function phase(string $phase): void
    {
        $this->phase = $phase;
    }

    private function identity(Redis|RedisCluster|Relay|Cluster $client): string
    {
        return $client::class . ($client === $this->client
            ? '#' . $this->clientIndex
            : '#peer-' . spl_object_id($client));
    }

    private function context(Redis|RedisCluster|Relay|Cluster $client): string
    {
        return "scenario={$this->scenario} seed={$this->seed} client_id=" . $this->identity($client);
    }

    /** @param list<mixed> $arguments */
    private function diagnostic(
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments = [],
    ): mixed {
        return InvocationRecorder::invoke(new StrictClientInvoker(), $client, $method, $arguments,
            $this->phase, $this->context($client) . ' diagnostic');
    }

    /** Record and enforce an exact match. */
    public function expect(string $name, mixed $expected, mixed $observed): void
    {
        $this->record(
            $name,
            $expected === $observed,
            ValueSummary::summarize($expected),
            ValueSummary::summarize($observed),
        );
    }

    /**
     * Record and enforce membership in a documented set of replies. Used where
     * PhpRedis and Relay legitimately report the same server outcome
     * differently; the observed value is always recorded.
     *
     * @param list<mixed> $allowed
     */
    public function expectOneOf(string $name, array $allowed, mixed $observed): void
    {
        $passed = false;
        foreach ($allowed as $candidate) {
            if ($candidate === $observed) {
                $passed = true;
                break;
            }
        }

        $this->record(
            $name,
            $passed,
            ['type' => 'one-of', 'allowed' => array_map(
                static fn (mixed $candidate): array => ValueSummary::summarize($candidate),
                $allowed,
            )],
            ValueSummary::summarize($observed),
        );
    }

    /**
     * Record and enforce a described expectation the caller has already
     * evaluated, keeping the concrete observation in the outcome.
     *
     * @param array<string, mixed>|null $observed
     */
    public function expectThat(
        string $name,
        string $expectation,
        bool $passed,
        ?array $observed = null,
    ): void {
        $this->record(
            $name,
            $passed,
            ['type' => 'expectation', 'description' => $expectation],
            $observed,
        );
    }

    /** Mark the client as possibly watching keys so cleanup can UNWATCH. */
    public function markWatchActive(bool $active): void
    {
        $this->watchActive = $active;
    }

    public function watchActive(): bool
    {
        return $this->watchActive;
    }

    /** @return list<StatefulObservation> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<array{name: string, passed: bool, expected: mixed, observed: mixed}> */
    public function postconditions(): array
    {
        return $this->postconditions;
    }

    private function record(string $name, bool $passed, mixed $expected, mixed $observed): void
    {
        $this->postconditions[] = [
            'name' => $name,
            'passed' => $passed,
            'expected' => $expected,
            'observed' => $observed,
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
