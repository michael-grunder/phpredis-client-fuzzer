<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Pipeline queue bookkeeping: heterogeneous replies, an empty pipeline, and a
 * discarded pipeline, each followed by an ordinary atomic command.
 *
 * Pipelining is capability gated at run time. A client that does not actually
 * enter pipeline mode (RedisCluster warns and stays in MULTI) reports a
 * structured `skipped` outcome instead of a failure.
 */
final class PipelineScenario extends ScenarioBase
{
    use MixedReplyOperations;

    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return $this->mixedReplyMethods();
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $client = $run->client();
        $pipeline = $this->clientConstant($client, 'PIPELINE', Redis::PIPELINE);
        $keys = $this->prepareMixedKeys($run, false);
        $value = $run->value('pipeline');

        $entry = $run->attempt('multi', [$pipeline], 'multi(PIPELINE)');
        $mode = $run->mode();
        if ($mode !== $pipeline) {
            $this->skip(sprintf(
                'Client does not enter pipeline mode: multi(%d) left getMode() at %s',
                $pipeline,
                $mode === null ? 'an unknown mode' : (string) $mode,
            ));
        }
        $run->expect('pipeline-acknowledges-entry', $client, $entry->reply);

        $this->queueMixedOperations($run, $keys, $pipeline, $value);

        $run->expect(
            'pipeline-exec-returns-every-queued-reply-in-order',
            $this->mixedReplies(),
            $run->call('exec'),
        );
        $run->expect('pipeline-exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());
        $this->verifyMixedCommit($run, $keys, $value, 'pipeline');

        /* An empty pipeline must drain to an empty reply list, not to the
           replies of the pipeline before it. */
        $run->expect('empty-pipeline-acknowledges-entry', $client, $run->call('multi', [$pipeline], 'multi(PIPELINE)'));
        $run->expect('empty-pipeline-enters-pipeline-mode', $pipeline, $run->mode());
        $run->expect('empty-pipeline-exec-returns-no-replies', [], $run->call('exec'));
        $run->expect('empty-pipeline-restores-atomic-mode', Redis::ATOMIC, $run->mode());

        /* A discarded pipeline must drop its buffered command entirely. */
        $run->expect('discarded-pipeline-acknowledges-entry', $client, $run->call('multi', [$pipeline], 'multi(PIPELINE)'));
        $run->expect(
            'discarded-pipeline-queues-write',
            $client,
            $run->call('set', [$keys['string'], $run->value('pipeline-discarded')]),
        );
        $run->expect('pipeline-discard-succeeds', true, $run->call('discard'));
        $run->expect('pipeline-discard-restores-atomic-mode', Redis::ATOMIC, $run->mode());
        $run->expect(
            'pipeline-discard-drops-the-queued-write',
            $value,
            $run->call('get', [$keys['string']]),
        );
        $run->expect(
            'atomic-command-after-pipeline-discard-is-aligned',
            3,
            $run->call('incr', [$keys['counter']]),
        );
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }
}
