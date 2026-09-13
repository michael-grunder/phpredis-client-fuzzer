<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ValueSummary;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * MULTI -> successful write -> runtime WRONGTYPE -> successful write -> EXEC.
 *
 * The error is produced by the server while the transaction executes, so the
 * client has to place it at the right index and keep the surrounding replies
 * aligned. How an errored element is represented differs between clients, so
 * that element is checked against a documented set and always recorded, while
 * arity, the neighbouring replies and the committed values are exact.
 */
final class ErrorAlignmentScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['multi', 'exec', 'discard', 'set', 'get', 'del', 'incr', 'rPush', 'lLen', 'getMode'];
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        /* One hash tag: this scenario is about reply alignment, not routing. */
        $list = $run->key('wrongtype-list');
        $first = $run->key('first');
        $second = $run->key('second');
        $value = $run->value('aligned');

        $run->call('del', [$list]);
        $run->call('del', [$first]);
        $run->call('del', [$second]);
        $run->expect('setup-list-has-one-element', 1, $run->call('rPush', [$list, $run->value('element')]));

        $run->expect('multi-acknowledges-entry', $run->client(), $run->call('multi'));
        $run->expect('multi-enters-multi-mode', Redis::MULTI, $run->mode());
        $run->expect('first-write-is-queued', $run->client(), $run->call('set', [$first, $value]));
        $run->expect('failing-command-is-queued', $run->client(), $run->call('incr', [$list]));
        $run->expect('failing-command-preserves-multi-mode', Redis::MULTI, $run->mode());
        $run->expect('second-write-is-queued', $run->client(), $run->call('set', [$second, $value]));

        /* EXEC reports the element error through getLastError on several
           clients, so the reply is inspected instead of being rejected. */
        $exec = $run->attempt('exec');
        $run->expectThat(
            'exec-returns-one-reply-per-queued-command',
            'EXEC returns a list of exactly three replies',
            is_array($exec->reply) && array_keys($exec->reply) === [0, 1, 2],
            $exec->describe(),
        );
        $replies = is_array($exec->reply) ? array_values($exec->reply) : [];
        $run->expect('successful-reply-before-the-error-is-aligned', true, $replies[0] ?? null);
        $run->expect('successful-reply-after-the-error-is-aligned', true, $replies[2] ?? null);
        $run->expectThat(
            'error-occupies-the-failing-position',
            'the second element reports the WRONGTYPE error (false, null, a raised error, or the error text)',
            $this->isErrorReply($replies[1] ?? null),
            ['reply' => ValueSummary::summarize($replies[1] ?? null)],
        );
        $run->expect('exec-restores-atomic-mode', Redis::ATOMIC, $run->mode());

        $run->expect('wrongtype-key-is-unchanged', 1, $run->call('lLen', [$list]));
        $run->expect('first-write-was-committed', $value, $run->call('get', [$first]));
        $run->expect('second-write-was-committed', $value, $run->call('get', [$second]));
        $run->expect('final-reads-preserve-atomic-mode', Redis::ATOMIC, $run->mode());
    }

    /**
     * Clients report an errored transaction element as `false`, as `null`, by
     * handing back the error text, or by raising it. Anything else means the
     * element was not reported as an error at all.
     */
    private function isErrorReply(mixed $reply): bool
    {
        if ($reply === false || $reply === null || $reply instanceof \Throwable) {
            return true;
        }

        return is_string($reply) && str_contains(strtoupper($reply), 'WRONGTYPE');
    }
}
