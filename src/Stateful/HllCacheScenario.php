<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class HllCacheScenario extends CacheScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return [...parent::requiredMethods($client), 'get', 'pfadd', 'pfcount', 'pfmerge', 'setOption'];
    }

    protected function unsupportedReason(Redis|RedisCluster|Relay|Cluster $client): ?string
    {
        return ($client instanceof Relay || $client instanceof Cluster)
            && !defined(Relay::class . '::OPT_USE_CACHE')
            ? 'Relay OPT_USE_CACHE is required for authoritative server counts' : null;
    }

    protected function fixtures(ScenarioRun $run): void
    {
        $dense = $run->key('dense');
        $sparse = $run->key('sparse');
        $merged = $run->key('merged');
        $missing = $run->key('missing');
        $elements = [];
        for ($i = 0; $i < 12000; $i++) {
            $elements[] = $run->value('element-' . $i);
        }

        // Rebuild for each header state, including when the seed is reused.
        foreach ([false, true] as $serverCountFirst) {
            $stage = $serverCountFirst ? 'cached-header' : 'uncached-header';
            $run->call('del', [[$dense, $sparse, $merged, $missing]]);
            $run->expect('dense-created', 1, $run->call('pfadd', [$dense, $elements]));
            $run->expect('sparse-created', 1, $run->call('pfadd', [$sparse, [$run->value('sparse-a'), $run->value('sparse-b')]]));
            if ($serverCountFirst) {
                $this->serverCount($run, $dense);
                $this->serverCount($run, $sparse);
            }
            $this->warm($run, $dense, $stage . ':dense', 0);
            $this->warm($run, $sparse, $stage . ':sparse', 1);
            $this->check($run, $stage . ':missing-warm', false, 'get', [$missing]);
            foreach ([$dense, $sparse, [$dense, $sparse, $missing], $missing] as $index => $keys) {
                $this->count($run, $keys, $stage . ':count-' . $index);
            }

            $mutation = [];
            for ($i = 0; $i < 2000; $i++) {
                $mutation[] = $run->value('mutation-' . $i);
            }
            $run->expect('dense-mutated', 1, $run->call('pfadd', [$dense, $mutation]));
            $this->warm($run, $dense, $stage . ':mutated');
            $this->count($run, $dense, $stage . ':mutated-count');
            $run->expect('merge-succeeds', true, $run->call('pfmerge', [$merged, [$dense, $sparse]]));
            $this->warm($run, $merged, $stage . ':merged');
            $this->count($run, $merged, $stage . ':merged-count');
            // Overwrite a destination that is already cached.
            $run->expect('merge-overwrites', true, $run->call('pfmerge', [$merged, [$sparse]]));
            $this->warm($run, $merged, $stage . ':overwritten');
            $this->count($run, $merged, $stage . ':overwritten-count');
        }
    }

    private function warm(ScenarioRun $run, string $key, string $label, ?int $encoding = null): void
    {
        $reply = $this->read($run, 'get', [$key], $label . ':warm');
        $run->expectThat($label . ':hll-string', 'Redis HYLL payload',
            is_string($reply) && strlen($reply) >= 16 && str_starts_with($reply, 'HYLL'));
        if (is_string($reply) && $encoding !== null) {
            $run->expect($label . ':encoding', $encoding, ord($reply[4]));
        }
    }

    /** @param string|list<string> $keys */
    private function count(ScenarioRun $run, string|array $keys, string $label): void
    {
        // Read locally before the oracle can update Redis's cardinality header.
        $first = $this->read($run, 'pfcount', [$keys], $label);
        $repeat = $this->read($run, 'pfcount', [$keys], $label . ':repeat');
        $expected = $this->serverCount($run, $keys);
        $run->expect($label . ':server-parity', $expected, $first);
        $run->expect($label . ':repeat-parity', $expected, $repeat);
    }

    /** @param string|list<string> $keys */
    private function serverCount(ScenarioRun $run, string|array $keys): int
    {
        $relay = $run->client() instanceof Relay || $run->client() instanceof Cluster;
        $option = $relay ? constant(Relay::class . '::OPT_USE_CACHE') : null;
        $previous = $relay ? $run->call('getOption', [$option]) : null;
        try {
            if ($relay) {
                $run->expect('oracle-disables-cache', true, $run->call('setOption', [$option, false]));
            }
            $reply = $run->call('pfcount', [$keys], 'oracle:pfcount');
        } finally {
            if ($relay) {
                $run->expect('oracle-restores-cache', true, $run->call('setOption', [$option, $previous]));
            }
        }
        if (!is_int($reply) || $reply < 0) {
            throw new \RuntimeException('Server PFCOUNT did not return a nonnegative integer');
        }

        return $reply;
    }
}
