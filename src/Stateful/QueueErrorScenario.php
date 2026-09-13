<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * MULTI -> server queue-time error -> EXEC.
 *
 * A raw GET with no arguments is rejected by the server as it is queued, which
 * is the one error class that aborts the whole transaction: the server answers
 * EXEC with EXECABORT and applies nothing. The invariants are that the queued
 * write never lands, that the client returns to atomic mode, and that the
 * connection keeps answering the right replies to the next commands.
 */
final class QueueErrorScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['multi', 'exec', 'discard', 'set', 'get', 'getMode', 'rawCommand', 'ping'];
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $client = $run->client();
        $key = $run->key('aborted');
        $initial = $run->value('initial');
        $value = $run->value('queued');

        $run->expect('setup-set-succeeds', true, $run->call('set', [$key, $initial]));
        $run->expect('setup-key-has-initial-value', $initial, $run->call('get', [$key]));

        $run->expect('multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());
        $run->expect('write-is-queued', $client, $run->call('set', [$key, $value]));

        /* Cluster clients route raw commands with a key or a node address. */
        $rawArguments = $this->isCluster($client) ? [$key, 'get'] : ['get'];
        $rejected = $run->attempt('rawCommand', $rawArguments, 'rawCommand(GET without arguments)');
        $run->expectThat(
            'queue-time-error-is-reported-to-the-caller',
            'the rejected command does not acknowledge queueing: a Redis error, an exception, false or null',
            $rejected->threw()
                || $rejected->error() !== null
                || $rejected->reply === false
                || $rejected->reply === null,
            $rejected->describe(),
        );
        $run->expect('queue-time-error-preserves-multi-mode', Redis::MULTI, $run->mode());

        $exec = $run->attempt('exec');
        $run->expectThat(
            'exec-aborts-the-transaction',
            'EXEC reports the aborted transaction: false, null, or a raised error',
            $exec->threw() || $exec->reply === false || $exec->reply === null,
            $exec->describe(),
        );
        $run->expect('aborted-exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());
        $run->expect('aborted-transaction-applied-no-write', $initial, $run->call('get', [$key]));

        $pingArguments = $this->isCluster($client) ? [$key] : [];
        $run->expectOneOf(
            'connection-answers-ping-after-the-abort',
            [true, 'PONG', '+PONG'],
            $run->call('ping', $pingArguments),
        );

        /* A stale queued reply would surface here as the wrong EXEC payload. */
        $run->expect('recovered-multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('recovered-write-is-queued', $client, $run->call('set', [$key, $value]));
        $run->expect('recovered-exec-returns-one-reply', [true], $run->call('exec'));
        $run->expect('recovered-exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());
        $run->expect('recovered-transaction-committed', $value, $run->call('get', [$key]));
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }
}
