<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Disconnect while replies are still owed, then recover on the same object.
 *
 * The transaction is abandoned with queued commands whose replies were never
 * drained. After the reconnect the next commands must be answered by their own
 * replies: a client that kept the old callback queue answers PING or GET with
 * a leftover reply, and a client that kept the mode believes it is still in a
 * transaction that no longer exists on the server.
 */
final class RecoveryScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['multi', 'exec', 'discard', 'set', 'get', 'getMode', 'close', 'ping'];
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $client = $run->client();
        $first = $run->key('first');
        $second = $run->key('second');
        $initial = $run->value('initial');
        $value = $run->value('abandoned');

        foreach ([$first, $second] as $index => $key) {
            $run->expect("setup-set-{$index}-succeeds", true, $run->call('set', [$key, $initial]));
            $run->expect("setup-key-{$index}-has-initial-value", $initial, $run->call('get', [$key]));
        }

        $run->expect('multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());
        $run->expect('first-write-is-queued', $client, $run->call('set', [$first, $value]));
        $run->expect('second-write-is-queued', $client, $run->call('set', [$second, $value]));

        $closed = $run->attempt('close');
        $run->expectThat(
            'close-drops-the-connection-with-replies-outstanding',
            'close() returns without raising',
            $closed->returned && ($closed->reply === true || $closed->reply === null),
            $closed->describe(),
        );
        $run->expect('close-restores-atomic-mode', Redis::ATOMIC, $run->mode());

        $pingArguments = $this->isCluster($client) ? [$first] : [];
        $ping = $run->attempt('ping', $pingArguments);
        if ($this->unusable($ping)) {
            /* Reconnect explicitly when the client does not do it lazily. */
            if (!$this->isCluster($client)
                && method_exists($client, 'connect')
                && method_exists($client, 'getHost')
                && method_exists($client, 'getPort')) {
                $host = $run->attempt('getHost');
                $port = $run->attempt('getPort');
                if (is_string($host->reply) && is_int($port->reply)) {
                    $run->attempt('connect', [$host->reply, $port->reply]);
                    $ping = $run->attempt('ping');
                }
            }
        }
        if ($this->unusable($ping)) {
            $this->skip(sprintf(
                'Client is not usable after close(): %s',
                $ping->threw()
                    ? 'raised ' . ($ping->exception?->getMessage() ?? 'an error')
                    : ($ping->error() ?? 'PING did not answer'),
            ));
        }
        $run->expectOneOf('ping-is-answered-by-its-own-reply', [true, 'PONG', '+PONG'], $ping->reply);

        $run->expect('abandoned-first-write-was-not-applied', $initial, $run->call('get', [$first]));
        $run->expect('abandoned-second-write-was-not-applied', $initial, $run->call('get', [$second]));

        /* Prove the reply queue is aligned again, not merely answering. */
        $run->expect('recovered-multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('recovered-write-is-queued', $client, $run->call('set', [$first, $value]));
        $run->expect('recovered-exec-returns-one-reply', [true], $run->call('exec'));
        $run->expect('recovered-exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());
        $run->expect('recovered-transaction-committed', $value, $run->call('get', [$first]));
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }

    private function unusable(ScenarioCall $ping): bool
    {
        return $ping->threw() || $ping->error() !== null || $ping->reply === false;
    }
}
