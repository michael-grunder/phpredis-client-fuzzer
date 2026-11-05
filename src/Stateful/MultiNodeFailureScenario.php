<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/** Fail each participant in turn, with interleaved replies and reuse of both connections. */
final class MultiNodeFailureScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        $methods = ['multi', 'exec', 'discard', 'set', 'get', 'getMode', 'getOption', 'rawCommand', 'ping'];
        return match ($this->scenario) {
            'cluster-watch-abort' => [...$methods, 'watch', 'unwatch'],
            'cluster-error-alignment' => [...$methods, 'del', 'rPush', 'incr', 'lLen'],
            default => $methods,
        };
    }

    protected function unsupportedReason(Redis|RedisCluster|Relay|Cluster $client): ?string
    {
        return $this->isCluster($client) ? null : 'Scenario requires a cluster client and two primary nodes';
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $watch = $this->scenario === 'cluster-watch-abort';
        $runtime = $this->scenario === 'cluster-error-alignment';
        $peer = $watch ? $this->peer($run, $peers) : null;
        $throwOnError = $run->client() instanceof Cluster && defined(Relay::class . '::OPT_THROW_ON_ERROR')
            && $run->call('getOption', [constant(Relay::class . '::OPT_THROW_ON_ERROR')]) === true;
        $offsets = $this->nodeOffsets($run);
        $keys = [$run->key('value', $offsets[0]), $run->key('value', $offsets[1])];

        // Failure first and failure last exercise different EXEC cleanup paths.
        foreach ([0, 1] as $failed) {
            $round = "failure-on-node-{$failed}";
            $run->phase('setup');
            $initial = [$run->value("{$round}-initial-0"), $run->value("{$round}-initial-1")];
            $values = [$run->value("{$round}-queued-0"), $run->value("{$round}-queued-1")];
            foreach ($keys as $index => $key) {
                $run->expect("{$round}:setup-{$index}", true, $run->call('set', [$key, $initial[$index]]));
                $run->expect("{$round}:initial-{$index}", $initial[$index], $run->call('get', [$key]));
                if ($peer !== null && $run->callPeer($peer, 'get', [$key]) !== $initial[$index]) {
                    $this->skip('Scenario peer does not observe both cluster keys');
                }
            }
            $list = $run->key('wrongtype-list', $offsets[$failed]);
            if ($runtime) {
                $run->call('del', [$list]);
                $run->expect("{$round}:list-setup", 1, $run->call('rPush', [$list, 'element']));
            }
            $run->phase('scheduled-call');
            if ($peer !== null) {
                $run->markWatchActive(true);
                foreach ($keys as $index => $key) {
                    $run->expect("{$round}:watch-{$index}", true, $run->call('watch', [$key]));
                }
                $initial[$failed] = $run->value("{$round}-peer");
                $run->expect("{$round}:peer-write", true, $run->callPeer($peer, 'set', [$keys[$failed], $initial[$failed]]));
                $run->expect("{$round}:peer-read", $initial[$failed], $run->callPeer($peer, 'get', [$keys[$failed]]));
            }

            $this->queued($run, $round, 'multi');
            $this->queued($run, $round, 'set', [$keys[0], $values[0]]);
            $this->queued($run, $round, 'get', [$keys[1]]);
            if ($runtime) {
                $this->queued($run, $round, 'incr', [$list]);
            }
            $this->queued($run, $round, 'set', [$keys[1], $values[1]]);
            $this->queued($run, $round, 'get', [$keys[0]]);

            if (!$watch && !$runtime) {
                // Unknown commands reach the server even when clients validate known commands' arity.
                $rejected = $run->attempt('rawCommand', [$keys[$failed], '__redis_client_tester_invalid_transaction_command__']);
                $run->expectThat("{$round}:server-rejects-command", 'server reports an unknown command',
                    ($rejected->reply === false || $rejected->threw())
                        && $this->hasError($rejected, 'unknown command'), $rejected->describe());
                $run->expect("{$round}:rejection-preserves-multi", Redis::MULTI, $run->mode());
            }

            $exec = $run->attempt('exec');
            $run->expect("{$round}:exec-restores-atomic", Redis::ATOMIC, $run->mode());
            $run->markWatchActive(false);
            $expectedValues = $values;
            $expectedReplies = [true, $initial[1], true, $values[0]];
            if ($runtime) {
                $reply = is_array($exec->reply) ? ($exec->reply[2] ?? null) : null;
                $error = $reply instanceof \Throwable ? $reply->getMessage() : $reply;
                $run->expectThat("{$round}:wrongtype-in-position-2", 'false, null, or a WRONGTYPE error',
                    $error === false || $error === null || (is_string($error) && str_contains($error, 'WRONGTYPE')),
                    $exec->describe());
                array_splice($expectedReplies, 2, 0, [$reply]);
                $run->expect("{$round}:list-preserved", 1, $run->call('lLen', [$list]));
            } else {
                $expectedValues[$failed] = $initial[$failed];
                foreach ($failed === 0 ? [0, 3] : [1, 2] as $position) {
                    $expectedReplies[$position] = false;
                }
            }

            if (!$watch && !$runtime && $run->client() instanceof RedisCluster) {
                // PhpRedis stops on EXECABORT; earlier participants may already have committed.
                $run->expectThat("{$round}:exec-reports-abort", 'RedisClusterException reporting EXEC failure',
                    $exec->exception instanceof \RedisClusterException
                        && (str_contains($exec->exception->getMessage(), 'EXECABORT')
                            || str_contains($exec->exception->getMessage(), 'Error processing EXEC across the cluster')),
                    $exec->describe());
                if ($failed === 0) {
                    $expectedValues[1] = $initial[1];
                }
            } elseif (!$runtime && $throwOnError) {
                $run->expectThat("{$round}:exec-reports-abort", 'Relay exception reporting the transaction abort',
                    $exec->exception instanceof \Relay\Exception
                        && ($watch ? $exec->exception->getMessage() === 'Transaction failed'
                            : str_contains($exec->exception->getMessage(), 'EXECABORT')),
                    $exec->describe());
            } else {
                $run->expect("{$round}:exec-did-not-throw", null, $exec->exception);
                $run->expect("{$round}:exec-reply-order", $expectedReplies, $exec->reply);
            }
            foreach ($keys as $index => $key) {
                $run->expect("{$round}:stored-value-{$index}", $expectedValues[$index], $run->call('get', [$key]));
                if ($peer !== null) {
                    $run->expect("{$round}:peer-value-{$index}", $expectedValues[$index], $run->callPeer($peer, 'get', [$key]));
                }
                $run->expectOneOf("{$round}:ping-{$index}", [true, 'PONG', '+PONG'], $run->call('ping', [$key]));
            }

            // Change watched keys again: recovery must not inherit any old watches.
            if ($peer !== null) {
                foreach ($keys as $key) {
                    $run->expect("{$round}:peer-before-recovery", true, $run->callPeer($peer, 'set', [$key, 'after-abort']));
                }
            }
            $recovered = [$run->value("{$round}-recovered-0"), $run->value("{$round}-recovered-1")];
            $this->queued($run, "{$round}:recovery", 'multi');
            foreach ($keys as $index => $key) {
                $this->queued($run, "{$round}:recovery", 'set', [$key, $recovered[$index]]);
            }
            foreach ($keys as $key) {
                $this->queued($run, "{$round}:recovery", 'get', [$key]);
            }
            $run->expect("{$round}:recovery-reply-order", [true, true, ...$recovered], $run->call('exec'));
            $run->expect("{$round}:recovery-atomic", Redis::ATOMIC, $run->mode());
            foreach ($keys as $index => $key) {
                $run->expect("{$round}:recovery-value-{$index}", $recovered[$index], $run->call('get', [$key]));
            }
        }
    }

    /** @return array{int, int} */
    private function nodeOffsets(ScenarioRun $run): array
    {
        $first = null;
        for ($offset = 0; $offset < 128; $offset++) {
            $node = $run->attempt('rawCommand', [$run->key('value', $offset), 'CLUSTER', 'MYID']);
            if ($this->hasError($node, 'NOPERM') || $this->hasError($node, 'unknown command')
                || $this->hasError($node, 'unknown subcommand')) {
                $this->skip('CLUSTER MYID is unavailable or not permitted');
            }
            $run->expectThat('node-identity-is-valid', 'CLUSTER MYID returns a 40-digit hexadecimal ID',
                !$node->failed() && is_string($node->reply) && preg_match('/^[a-f0-9]{40}$/i', $node->reply) === 1,
                $node->describe());
            $first ??= $node->reply;
            if ($node->reply !== $first) {
                $run->expectThat('keys-route-to-distinct-primaries', 'different CLUSTER MYID replies', true,
                    ['first_node' => $first, 'second_node' => $node->reply]);
                return [0, $offset];
            }
        }
        $this->skip('No second primary found in 128 hash tags; check topology and hash tags in the client prefix');
    }

    /** @param list<mixed> $arguments */
    private function queued(ScenarioRun $run, string $round, string $method, array $arguments = []): void
    {
        $run->expect("{$round}:{$method}-queued", $run->client(), $run->call($method, $arguments));
        $run->expect("{$round}:{$method}-multi-mode", Redis::MULTI, $run->mode());
    }

    private function hasError(ScenarioCall $call, string $text): bool
    {
        foreach ([...$call->redisErrors, $call->exception?->getMessage() ?? ''] as $error) {
            if (str_contains($error, $text)) {
                return true;
            }
        }
        return false;
    }
}
