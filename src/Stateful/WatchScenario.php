<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Watch semantics proved with a second connection.
 *
 * `watch-abort`: WATCH -> a peer writes the watched key -> MULTI/EXEC must
 * abort and must not apply the queued write.
 *
 * `watch-unwatch-commit`: WATCH -> UNWATCH -> a peer writes the watched key ->
 * MULTI/EXEC must commit, which is what proves UNWATCH cleared the watch. A
 * discarded transaction cannot prove that.
 *
 * Both need a scenario peer; without one they report `skipped`.
 */
final class WatchScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['multi', 'exec', 'discard', 'watch', 'unwatch', 'set', 'get', 'getMode'];
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $commits = $this->scenario === 'watch-unwatch-commit';
        $client = $run->client();
        $peer = $this->peer($run, $peers);
        $key = $run->key('watched');
        $initial = $run->value('initial');
        $peerValue = $run->value('peer');
        $value = $run->value('committed');

        $run->expect('setup-set-succeeds', true, $run->call('set', [$key, $initial]));
        $run->expect('setup-key-has-initial-value', $initial, $run->call('get', [$key]));

        /* A peer with a different prefix, database or target would make every
           later conclusion wrong, so prove it reads the same key first. */
        $observed = $run->callPeer($peer, 'get', [$key]);
        if ($observed !== $initial) {
            $this->skip(
                'Scenario peer does not observe the scenario key; it is not an'
                . ' equivalent connection to the same keyspace',
            );
        }

        $run->phase('scheduled-call');

        $run->markWatchActive(true);
        $run->expect('watch-succeeds', true, $run->call('watch', [$key]));
        if ($commits) {
            $run->expect('unwatch-succeeds', true, $run->call('unwatch'));
            $run->markWatchActive(false);
        }

        $run->expect('peer-write-succeeds', true, $run->callPeer($peer, 'set', [$key, $peerValue]));
        $run->expect('peer-write-is-visible', $peerValue, $run->callPeer($peer, 'get', [$key]));

        $run->expect('multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());
        $run->expect('write-is-queued', $client, $run->call('set', [$key, $value]));

        $exec = $run->attempt('exec');
        /* EXEC releases every watch, whichever way it ended. */
        $run->markWatchActive(false);

        if ($commits) {
            $run->expect('exec-commits-after-unwatch', [true], $exec->reply);
            $run->expect('exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());
            $run->expect('committed-value-is-visible', $value, $run->call('get', [$key]));
            $run->expect('peer-observes-the-commit', $value, $run->callPeer($peer, 'get', [$key]));
        } else {
            $run->expectThat(
                'exec-aborts-after-the-watched-key-changed',
                'EXEC reports the aborted transaction: false, null, or a raised error',
                $exec->threw() || $exec->reply === false || $exec->reply === null,
                $exec->describe(),
            );
            $run->expect('aborted-exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());
            $run->expect('aborted-transaction-applied-no-write', $peerValue, $run->call('get', [$key]));
            $run->expect('peer-observes-no-write', $peerValue, $run->callPeer($peer, 'get', [$key]));
        }
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }
}
