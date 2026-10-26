<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/** Ordered, bounded cache fixtures; all calls retain the scenario diagnostics. */
abstract class CacheScenarioBase extends ScenarioBase
{
    final protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        foreach (['OPT_SERIALIZER', 'OPT_COMPRESSION'] as $name) {
            $class = $run->client() instanceof Relay || $run->client() instanceof Cluster
                ? Relay::class : Redis::class;
            if (defined($class . '::' . $name)) {
                $option = constant($class . '::' . $name);
                if ($run->call('getOption', [$option]) !== 0) {
                    $this->skip('Cache fixtures require serializer and compression none; client options are preserved');
                }
            }
        }
        $run->phase('scheduled-call');
        $this->fixtures($run);
    }

    abstract protected function fixtures(ScenarioRun $run): void;

    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['getMode', 'getOption', 'del'];
    }

    /**
     * Record counter deltas without requiring hits: admission, eviction and
     * other workers can affect Relay's process-wide counters.
     *
     * @param list<mixed> $arguments
     */
    protected function read(ScenarioRun $run, string $method, array $arguments, string $label): mixed
    {
        $before = $this->counters($run);
        $reply = $run->call($method, $arguments, $label);
        $after = $this->counters($run);
        if ($before !== null && $after !== null) {
            $delta = [];
            foreach ($before as $name => $value) {
                $delta[$name] = $after[$name] - $value;
            }
            $run->expectThat($label . ':cache-counters', 'Observed process-wide Relay counter deltas', true, $delta);
        }

        return $reply;
    }

    /** @return array{hits: int, misses: int, errors: int}|null */
    private function counters(ScenarioRun $run): ?array
    {
        $client = $run->client();
        if (!$client instanceof Relay && !$client instanceof Cluster) {
            return null;
        }
        $reply = $run->call('stats', [], 'cache:stats');
        if (!is_array($reply) || !isset($reply['stats']) || !is_array($reply['stats'])) {
            return null;
        }
        $stats = $reply['stats'];
        if (!isset($stats['hits'], $stats['misses'], $stats['errors'])
            || !is_int($stats['hits']) || !is_int($stats['misses']) || !is_int($stats['errors'])) {
            return null;
        }

        return ['hits' => $stats['hits'], 'misses' => $stats['misses'], 'errors' => $stats['errors']];
    }

    /** @param list<mixed> $arguments */
    protected function check(ScenarioRun $run, string $label, mixed $expected, string $method, array $arguments): void
    {
        $run->expect($label, $expected, $this->read($run, $method, $arguments, $label));
    }

    /** Compare unordered replies without losing the concrete original observation. */
    protected function unordered(ScenarioRun $run, string $label, mixed $expected, mixed $reply, bool $map = false): void
    {
        if (is_array($expected) && is_array($reply)) {
            if ($map) {
                ksort($expected);
                ksort($reply);
            } else {
                sort($expected);
                sort($reply);
            }
        }
        $run->expect($label, $expected, $reply);
    }
}
