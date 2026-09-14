<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;

final class StatefulScenarioRegistry
{
    /** @return list<string> */
    public static function names(): array
    {
        return [
            'transaction-exec',
            'transaction-discard',
            'watch-unwatch-discard',
            'transaction-mixed-replies',
            'transaction-cross-slot-replies',
            'transaction-error-alignment',
            'transaction-queue-error',
            'transaction-local-rejection',
            'watch-abort',
            'watch-unwatch-commit',
            'pipeline-replies',
            'queued-value-mutation',
            'disconnect-recovery',
            'cache-hll',
            'cache-collections',
        ];
    }

    /**
     * Expand the CLI-style scenario selection. `none` disables scenarios;
     * `random` chooses a deterministic random subset (including the empty
     * subset) from the named scenarios or, when used alone, the full catalog.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function resolve(array $names, int $seed): array
    {
        $normalizedNames = array_map(static fn (string $name): string => strtolower(trim($name)), $names);
        if ($names === [] || in_array('none', $normalizedNames, true)) {
            return [];
        }

        $random = false;
        $explicit = [];
        foreach ($names as $name) {
            $normalized = strtolower(trim($name));
            if ($normalized === 'random') {
                $random = true;
                continue;
            }
            $explicit[] = $normalized;
        }

        if (!$random) {
            return $explicit;
        }

        $candidates = self::names();
        foreach ($explicit as $name) {
            self::create($name);
        }
        foreach ($candidates as $name) {
            self::create($name);
        }

        /* Hashing keeps selection reproducible without consuming the command RNG. */
        $mask = hexdec(substr(hash('sha256', 'scenarios:' . $seed), 0, 8));
        $selected = array_values(array_unique($explicit));
        foreach ($candidates as $index => $name) {
            if (($mask & (1 << $index)) !== 0 && !in_array($name, $selected, true)) {
                $selected[] = $name;
            }
        }

        return $selected;
    }

    public static function create(
        string $name,
        ClientInvoker $clientInvoker = new StrictClientInvoker(),
    ): StatefulScenario
    {
        return match (strtolower($name)) {
            'transaction-exec' => new TransactionScenario('transaction-exec', $clientInvoker),
            'transaction-discard' => new TransactionScenario('transaction-discard', $clientInvoker),
            'watch-unwatch-discard' => new TransactionScenario('watch-unwatch-discard', $clientInvoker),
            'transaction-mixed-replies' => new MixedReplyScenario('transaction-mixed-replies', $clientInvoker),
            'transaction-cross-slot-replies' => new MixedReplyScenario('transaction-cross-slot-replies', $clientInvoker),
            'transaction-error-alignment' => new ErrorAlignmentScenario('transaction-error-alignment', $clientInvoker),
            'transaction-queue-error' => new QueueErrorScenario('transaction-queue-error', $clientInvoker),
            'transaction-local-rejection' => new LocalRejectionScenario('transaction-local-rejection', $clientInvoker),
            'watch-abort' => new WatchScenario('watch-abort', $clientInvoker),
            'watch-unwatch-commit' => new WatchScenario('watch-unwatch-commit', $clientInvoker),
            'pipeline-replies' => new PipelineScenario('pipeline-replies', $clientInvoker),
            'queued-value-mutation' => new QueuedValueScenario('queued-value-mutation', $clientInvoker),
            'disconnect-recovery' => new RecoveryScenario('disconnect-recovery', $clientInvoker),
            'cache-hll' => new HllCacheScenario('cache-hll', $clientInvoker),
            'cache-collections' => new CollectionCacheScenario('cache-collections', $clientInvoker),
            default => throw new \InvalidArgumentException(
                "Unknown stateful scenario: {$name}",
            ),
        };
    }

    /**
     * @param list<string> $names
     * @return list<StatefulScenario>
     */
    public static function select(
        array $names,
        int $seed = 0,
        ClientInvoker $clientInvoker = new StrictClientInvoker(),
    ): array
    {
        $names = self::resolve($names, $seed);
        $selected = [];
        foreach ($names as $name) {
            $selected[] = self::create($name, $clientInvoker);
        }

        /* A stable seed-derived order avoids consuming the fuzzer's global RNG. */
        usort(
            $selected,
            static fn (StatefulScenario $left, StatefulScenario $right): int =>
                strcmp(
                    hash('sha256', $seed . ':' . $left->name()),
                    hash('sha256', $seed . ':' . $right->name()),
                ),
        );

        return $selected;
    }
}
