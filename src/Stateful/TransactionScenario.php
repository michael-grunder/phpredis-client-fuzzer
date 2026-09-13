<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * The original commit/discard transactions: they establish a known value,
 * require MULTI and every queued SET to acknowledge with the client object,
 * and check the terminal reply, the restored mode, and the committed or
 * preserved values.
 *
 * `transaction-exec` deliberately writes two different hash tags, so a cluster
 * client has to manage the transaction across nodes and reassemble the replies
 * in queue order.
 */
final class TransactionScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        $required = ['multi', 'discard', 'set', 'get', 'getMode'];
        if ($this->scenario === 'watch-unwatch-discard') {
            $required[] = 'watch';
            $required[] = 'unwatch';
        } elseif ($this->scenario === 'transaction-exec') {
            $required[] = 'exec';
        }

        return $required;
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $key = $run->key('value');
        $key2 = $run->key('value', 1);
        $value = 'state-' . $run->seed();
        $initial = 'initial-' . $run->seed();
        $keys = $this->scenario === 'transaction-exec' ? [$key, $key2] : [$key];

        // Reinitialize even when the same seed and client are reused.
        foreach ($keys as $index => $scenarioKey) {
            $run->expect(
                "setup-set-{$index}-succeeds",
                true,
                $run->call('set', [$scenarioKey, $initial]),
            );
            $run->expect(
                "setup-key-{$index}-has-initial-value",
                $initial,
                $run->call('get', [$scenarioKey]),
            );
        }

        if ($this->scenario === 'watch-unwatch-discard') {
            $run->markWatchActive(true);
            $run->expect('watch-succeeds', true, $run->call('watch', [$key]));
            $run->expect('unwatch-succeeds', true, $run->call('unwatch'));
            $run->markWatchActive(false);
        }

        $run->expect('multi-acknowledges-entry', $run->client(), $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());
        foreach ($keys as $index => $scenarioKey) {
            $run->expect(
                "set-{$index}-is-queued",
                $run->client(),
                $run->call('set', [$scenarioKey, $value]),
            );
            $run->expect("set-{$index}-preserves-multi-mode", Redis::MULTI, $run->mode());
        }

        if ($this->scenario === 'transaction-exec') {
            $run->expect('exec-returns-two-successful-replies', [true, true], $run->call('exec'));
        } else {
            $run->expect('discard-succeeds', true, $run->call('discard'));
        }
        $run->expect('terminal-operation-restores-atomic-mode', Redis::ATOMIC, $run->mode());

        foreach ($keys as $index => $scenarioKey) {
            $run->expect(
                $this->scenario === 'transaction-exec' ? "exec-commits-key-{$index}" : 'discard-preserves-initial-value',
                $this->scenario === 'transaction-exec' ? $value : $initial,
                $run->call('get', [$scenarioKey]),
            );
        }
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }
}
