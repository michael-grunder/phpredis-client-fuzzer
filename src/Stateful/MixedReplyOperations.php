<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

/**
 * The heterogeneous queue shared by the transaction and pipeline scenarios:
 * a missing GET, a SET, an INCR on a fresh counter and an empty HGETALL.
 *
 * Every reply has a different type, so the EXEC array pins reply count, order,
 * indexes, types and values at once.
 */
trait MixedReplyOperations
{
    /**
     * Remove the scenario's own keys so the queued replies are predictable.
     * `$spread` gives every key its own cluster hash tag, which forces a
     * cluster client to manage the transaction across nodes.
     *
     * @return array{missing: string, string: string, counter: string, hash: string}
     */
    protected function prepareMixedKeys(ScenarioRun $run, bool $spread): array
    {
        $keys = [];
        foreach (['missing', 'string', 'counter', 'hash'] as $index => $name) {
            /* One DEL per key: a multi-key DEL would have to be split by slot. */
            $key = $run->key('mixed-' . $name, $spread ? $index : 0);
            $run->call('del', [$key]);
            $keys[$name] = $key;
        }

        $run->expect('setup-read-key-is-absent', false, $run->call('get', [$keys['missing']]));

        /** @var array{missing: string, string: string, counter: string, hash: string} $keys */
        return $keys;
    }

    /**
     * @param array{missing: string, string: string, counter: string, hash: string} $keys
     * @param int $mode The queue mode the client must stay in while queueing.
     */
    protected function queueMixedOperations(
        ScenarioRun $run,
        array $keys,
        int $mode,
        string $value,
    ): void {
        /** @var array<string, list<mixed>> $operations */
        $operations = [
            'get' => [$keys['missing']],
            'set' => [$keys['string'], $value],
            'incr' => [$keys['counter']],
            'hGetAll' => [$keys['hash']],
        ];
        foreach ($operations as $method => $arguments) {
            $name = strtolower($method);
            $run->expect("{$name}-is-queued", $run->client(), $run->call($method, $arguments));
            $run->expect("{$name}-preserves-queue-mode", $mode, $run->mode());
        }
    }

    /**
     * The exact reply the queued operations must produce, in queue order.
     *
     * @return list<mixed>
     */
    protected function mixedReplies(): array
    {
        return [false, true, 1, []];
    }

    /**
     * Read the committed state back without relying on serializer round trips
     * for the INCR value: a second INCR proves the committed counter was 1.
     *
     * @param array{missing: string, string: string, counter: string, hash: string} $keys
     */
    protected function verifyMixedCommit(
        ScenarioRun $run,
        array $keys,
        string $value,
        string $prefix,
        int $counter = 2,
    ): void {
        $run->expect("{$prefix}-committed-string-value", $value, $run->call('get', [$keys['string']]));
        $run->expect("{$prefix}-committed-counter", $counter, $run->call('incr', [$keys['counter']]));
        $run->expect("{$prefix}-left-hash-empty", [], $run->call('hGetAll', [$keys['hash']]));
        $run->expect("{$prefix}-read-key-is-still-absent", false, $run->call('get', [$keys['missing']]));
    }

    /** @return list<string> */
    protected function mixedReplyMethods(): array
    {
        return ['multi', 'exec', 'discard', 'set', 'get', 'del', 'incr', 'hGetAll', 'getMode'];
    }
}
