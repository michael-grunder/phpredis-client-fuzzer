<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
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

        $run->phase('scheduled-call');

        $run->expect('multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());
        $run->expect('first-write-is-queued', $client, $run->call('set', [$first, $value]));
        $run->expect('second-write-is-queued', $client, $run->call('set', [$second, $value]));

        /* Relay's getHost()/getPort() throw once the connection is closed, so
           learn the endpoint, and the timeouts an explicit connect() would
           otherwise reset to "unlimited", while it is still open. */
        $endpoint = null;
        $readTimeout = null;
        if (!$this->isCluster($client)
            && method_exists($client, 'connect')
            && method_exists($client, 'getHost')
            && method_exists($client, 'getPort')) {
            $host = $run->attempt('getHost');
            $port = $run->attempt('getPort');
            if (is_string($host->reply) && is_int($port->reply)) {
                $endpoint = [$host->reply, $port->reply];
                if (method_exists($client, 'getTimeout')) {
                    $timeout = $run->attempt('getTimeout');
                    if (is_float($timeout->reply) || is_int($timeout->reply)) {
                        $endpoint[] = (float) $timeout->reply;
                    }
                }
                if (method_exists($client, 'getReadTimeout')) {
                    $read = $run->attempt('getReadTimeout');
                    if (is_float($read->reply) || is_int($read->reply)) {
                        $readTimeout = (float) $read->reply;
                    }
                }
            }
        }
        $configuration = ClientFactory::configurationFor($client);
        if ($endpoint !== null && $configuration !== null) {
            $endpoint = [
                $configuration->tls->endpoint($configuration->host),
                $configuration->port,
                $endpoint[2] ?? $configuration->timeout,
                null,
                0,
                $readTimeout ?? $configuration->readTimeout,
                ($configuration->auth === null ? [] : ['auth' => $configuration->auth])
                    + $configuration->tls->context(),
            ];
        }
        $closed = $run->attempt('close');
        $run->expectThat(
            'close-drops-the-connection-with-replies-outstanding',
            'close() returns without raising',
            $closed->returned && ($closed->reply === true || $closed->reply === null),
            $closed->describe(),
        );
        /* Observe the mode now but judge it after the client is usable again:
           Relay throws "Must be connected" here, and failing before the
           reconnect below would leave a standalone client dead for the rest of
           the run instead of only failing this scenario. */
        $modeAfterClose = $run->mode();

        $pingArguments = $this->isCluster($client) ? [$first] : [];
        $ping = $run->attempt('ping', $pingArguments);
        if ($this->unusable($ping) && $endpoint !== null) {
            /* Reconnect explicitly when the client does not do it lazily,
               restoring the read timeout so a later stalled reply still
               raises instead of blocking the run forever. */
            $run->attempt('connect', $endpoint);
            if ($readTimeout !== null) {
                $run->attempt('setOption', [Redis::OPT_READ_TIMEOUT, $readTimeout]);
            }
            $ping = $run->attempt('ping');
        }
        if ($this->unusable($ping)) {
            $this->skip(sprintf(
                'Client is not usable after close(): %s',
                $ping->threw()
                    ? 'raised ' . ($ping->exception?->getMessage() ?? 'an error')
                    : ($ping->error() ?? 'PING did not answer'),
            ));
        }
        $run->expect('close-restores-atomic-mode', Redis::ATOMIC, $modeAfterClose);
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
