<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

/**
 * Runs testcases through both clients from identical starting state:
 *
 *   reset -> seed -> first client  -> record
 *   reset -> seed -> second client -> record
 *
 * using one neutral raw connection for every reset, seed, and snapshot, and a
 * freshly connected client for every execution.
 *
 * @phpstan-import-type Server from DiffRuntime
 * @phpstan-import-type Execution from DiffRuntime
 */
final class DifferentialExecutor
{
    /** @var resource|null */
    private $socket = null;

    /**
     * The case currently executing. A fatal error in a client skips every
     * finally block, so this still names the culprit at shutdown.
     */
    private ?TestCase $running = null;

    /** @param Server $server */
    public function __construct(private readonly array $server)
    {
    }

    /** @return Server */
    public function server(): array
    {
        return $this->server;
    }

    /** @return resource */
    public function socket()
    {
        return $this->socket ??= DiffRuntime::connect($this->server);
    }

    public function toleranceMs(): int
    {
        return $this->server['ttl_tolerance_ms'];
    }

    /**
     * @param bool $perOpState Snapshot state after every operation, to find
     *        the first operation that made state diverge (the slow path).
     */
    public function run(TestCase $case, bool $perOpState = false): RunPair
    {
        $runtime = $case->runtime();
        [$firstClient, $secondClient] = $case->order;

        $this->running = $case;
        try {
            $first = DiffRuntime::execute($this->socket(), $firstClient, $runtime, $this->server, $perOpState);
            $second = DiffRuntime::execute($this->socket(), $secondClient, $runtime, $this->server, $perOpState);
        } finally {
            $this->cleanup($case);
            $this->running = null;
        }

        return new RunPair($case, $first, $second, DiffRuntime::compare($runtime, $first, $second, $this->toleranceMs(), $this->server['strict_messages']));
    }

    /**
     * Run the case twice through the same client. Returns both runs when they
     * differ, or null when the client reproduced its own behavior.
     */
    public function selfCheck(TestCase $case, string $client): ?RunPair
    {
        $pair = $this->run($case->withOrder([$client, $client === DiffRuntime::RELAY ? DiffRuntime::PHPREDIS : DiffRuntime::RELAY]));
        $runtime = $case->runtime();

        $this->running = $case;
        try {
            $again = DiffRuntime::execute($this->socket(), $client, $runtime, $this->server, false);
        } finally {
            $this->cleanup($case);
            $this->running = null;
        }

        $comparison = DiffRuntime::compare($runtime, $pair->first, $again, $this->toleranceMs(), $this->server['strict_messages']);
        if (!$comparison['diverged']) {
            return null;
        }

        return new RunPair($case->withOrder([$client, $client === DiffRuntime::RELAY ? DiffRuntime::PHPREDIS : DiffRuntime::RELAY]), $pair->first, $again, $comparison);
    }

    public function running(): ?TestCase
    {
        return $this->running;
    }

    public function cleanup(TestCase $case): void
    {
        DiffRuntime::reset($this->socket(), $case->prefix);
    }

    /**
     * Neutral-connection facts about the server for failure metadata.
     *
     * @return array<string, mixed>
     */
    public function serverFacts(): array
    {
        $facts = [];
        try {
            $info = DiffRuntime::command($this->socket(), ['INFO', 'server']);
            foreach (explode("\n", is_string($info) ? $info : '') as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#' && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    if (in_array($name, ['redis_version', 'valkey_version', 'server_name', 'redis_mode', 'os', 'arch_bits', 'redis_git_sha1', 'redis_build_id'], true)) {
                        $facts[$name] = $value;
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $facts['info_error'] = $throwable->getMessage();
        }

        try {
            $config = DiffRuntime::command($this->socket(), ['CONFIG', 'GET', '*max-*']);
            $pairs = [];
            if (is_array($config)) {
                for ($i = 0; $i + 1 < count($config); $i += 2) {
                    $name = $config[$i];
                    $value = $config[$i + 1];
                    if (is_string($name) && is_scalar($value)) {
                        $pairs[$name] = (string) $value;
                    }
                }
            }
            ksort($pairs);
            $facts['config'] = $pairs;
        } catch (\Throwable $throwable) {
            $facts['config_error'] = $throwable->getMessage();
        }

        return $facts;
    }
}
