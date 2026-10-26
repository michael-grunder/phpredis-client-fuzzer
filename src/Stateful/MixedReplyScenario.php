<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * MULTI -> GET (missing) -> SET -> INCR -> HGETALL -> EXEC.
 *
 * The reply array pins count, order, indexes, types and values, so a client
 * that drops, reorders, or retypes one queued reply fails here.
 *
 * `transaction-cross-slot-replies` runs the same sequence with a distinct hash
 * tag per key. On a cluster client that makes the transaction span nodes and
 * checks that the per-node replies are reassembled in queue order; on a
 * standalone client it is the same transaction with four unrelated keys.
 */
final class MixedReplyScenario extends ScenarioBase
{
    use MixedReplyOperations;

    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return $this->mixedReplyMethods();
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $spread = $this->scenario === 'transaction-cross-slot-replies';
        $keys = $this->prepareMixedKeys($run, $spread);
        $value = $run->value('mixed');

        $run->phase('scheduled-call');

        $run->expect('multi-acknowledges-entry', $run->client(), $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());

        $this->queueMixedOperations($run, $keys, Redis::MULTI, $value);

        $run->expect(
            'exec-returns-every-queued-reply-in-order',
            $this->mixedReplies(),
            $run->call('exec'),
        );
        $run->expect('exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());

        $this->verifyMixedCommit($run, $keys, $value, 'exec');
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }
}
