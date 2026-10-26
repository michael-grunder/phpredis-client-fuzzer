<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Queued serialized values whose caller-side inputs are mutated and released
 * before EXEC.
 *
 * A client that keeps a borrowed pointer to the caller's value instead of
 * taking its own copy at queue time commits the mutated bytes, or reads freed
 * memory. The committed values must match the snapshots taken before the
 * mutation.
 *
 * The compound case needs a serializer: with `OPT_SERIALIZER` set to none an
 * array argument is not a value the client can send, so only the string case
 * runs.
 */
final class QueuedValueScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['multi', 'exec', 'discard', 'set', 'get', 'del', 'getOption', 'getMode'];
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $client = $run->client();
        $stringKey = $run->key('queued-string');
        $compoundKey = $run->key('queued-compound');
        $run->call('del', [$stringKey]);
        $run->call('del', [$compoundKey]);

        $serializer = $run->call('getOption', [Redis::OPT_SERIALIZER]);
        $serializes = is_numeric($serializer) && (int) $serializer !== Redis::SERIALIZER_NONE;

        $run->phase('scheduled-call');

        $run->expect('multi-acknowledges-entry', $client, $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());

        $payload = $run->value('payload') . ':' . str_repeat('ab', 48);
        $expectedString = $payload;
        $run->expect('string-write-is-queued', $client, $run->call('set', [$stringKey, $payload]));
        /* Mutate in place and release the caller's copy while it is queued. */
        $payload[0] = 'Z';
        $payload .= ':mutated-after-queueing';
        unset($payload);

        $expectedCompound = null;
        if ($serializes) {
            $inner = $run->value('inner');
            $expectedCompound = [$inner, 'second-element'];
            /* A reference inside the array defeats copy on write, so a client
               that serializes lazily would observe the mutation below. */
            $compound = [&$inner, 'second-element'];
            $run->expect('compound-write-is-queued', $client, $run->call('set', [$compoundKey, $compound]));
            $inner = 'mutated-after-queueing';
            $compound[] = 'appended-after-queueing';
            unset($compound, $inner);
        }
        gc_collect_cycles();

        $run->expect(
            'exec-returns-one-reply-per-queued-write',
            $serializes ? [true, true] : [true],
            $run->call('exec'),
        );
        $run->expect('exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());

        $run->expect(
            'committed-string-is-the-value-that-was-queued',
            $expectedString,
            $run->call('get', [$stringKey]),
        );
        if ($expectedCompound !== null) {
            $run->expect(
                'committed-compound-value-is-the-value-that-was-queued',
                $expectedCompound,
                $run->call('get', [$compoundKey]),
            );
        }
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }
}
