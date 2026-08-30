<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

final class StatefulScenarioRegistry
{
    /** @return list<string> */
    public static function names(): array
    {
        return [
            'transaction-exec',
            'transaction-discard',
            'watch-unwatch-discard',
        ];
    }

    public static function create(string $name): StatefulScenario
    {
        return match (strtolower($name)) {
            'transaction-exec' => new TransactionScenario('transaction-exec'),
            'transaction-discard' => new TransactionScenario('transaction-discard'),
            'watch-unwatch-discard' => new TransactionScenario('watch-unwatch-discard'),
            default => throw new \InvalidArgumentException(
                "Unknown stateful scenario: {$name}",
            ),
        };
    }

    /**
     * @param list<string> $names
     * @return list<StatefulScenario>
     */
    public static function select(array $names, int $seed = 0): array
    {
        $selected = [];
        foreach ($names as $name) {
            $selected[] = self::create($name);
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
