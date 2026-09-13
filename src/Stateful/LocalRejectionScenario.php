<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * MULTI -> client parser rejection -> valid command -> EXEC.
 *
 * SINTERCARD with a negative LIMIT is rejected by the argument parsers of both
 * PhpRedis and Relay before anything is written to the socket. Nothing was
 * queued on the server, so EXEC must return exactly one reply per command that
 * really was sent: a client that still booked a reply slot ends up misaligned.
 *
 * The rejection is established first in atomic mode. A client that sends the
 * call instead of rejecting it reports `skipped`, not a failure.
 */
final class LocalRejectionScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['multi', 'exec', 'discard', 'set', 'get', 'del', 'sIntercard', 'getMode'];
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $client = $run->client();
        $first = $run->key('first');
        $second = $run->key('second');
        $value = $run->value('kept');

        $run->call('del', [$first]);
        $run->call('del', [$second]);

        $probe = $run->attempt('sIntercard', [[$first], -1], 'sIntercard(negative LIMIT)');
        if (!$probe->rejectedLocally()) {
            $this->skip(sprintf(
                'Client does not reject a negative SINTERCARD LIMIT locally: %s',
                $probe->threw()
                    ? 'raised ' . ($probe->exception?->getMessage() ?? 'an error')
                    : ($probe->error() ?? 'the server answered the call'),
            ));
        }
        $run->expectThat(
            'client-rejects-the-call-without-sending-it',
            'the client answers false locally, with no Redis error',
            true,
            $probe->describe(),
        );

        $run->phase('scheduled-call');

        $run->expect('multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());
        $run->expect('first-write-is-queued', $client, $run->call('set', [$first, $value]));

        $rejected = $run->attempt('sIntercard', [[$first], -1], 'sIntercard(negative LIMIT)');
        $run->expectThat(
            'rejected-call-does-not-acknowledge-queueing',
            'the client answers false locally instead of returning itself',
            $rejected->rejectedLocally(),
            $rejected->describe(),
        );
        $run->expect('rejected-call-preserves-multi-mode', Redis::MULTI, $run->mode());

        $run->expect('second-write-is-queued', $client, $run->call('set', [$second, $value]));
        $run->expect(
            'exec-returns-only-the-commands-that-were-sent',
            [true, true],
            $run->call('exec'),
        );
        $run->expect('exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());
        $run->expect('first-write-was-committed', $value, $run->call('get', [$first]));
        $run->expect('second-write-was-committed', $value, $run->call('get', [$second]));
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }
}
