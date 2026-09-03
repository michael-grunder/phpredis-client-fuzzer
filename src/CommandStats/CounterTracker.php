<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

final class CounterTracker
{
    /** @var array<string, array<string, int>> */
    private array $previous = [];

    /** @var array<string, int> */
    private array $primary = [];

    /** @var array<string, int> */
    private array $replica = [];

    public function update(Sample $sample): void
    {
        foreach ($sample->readings as $reading) {
            $key = $reading->node->key();
            if (isset($this->previous[$key])) {
                foreach ($reading->calls as $command => $current) {
                    $prior = $this->previous[$key][$command] ?? 0;
                    $increment = $current >= $prior ? $current - $prior : $current;
                    if ($increment === 0) {
                        continue;
                    }

                    if ($reading->node->role === NodeRole::Primary) {
                        $this->primary[$command] = ($this->primary[$command] ?? 0) + $increment;
                    } else {
                        $this->replica[$command] = ($this->replica[$command] ?? 0) + $increment;
                    }
                }
            }
            $this->previous[$key] = $reading->calls;
        }
    }

    /** @return list<array{command: string, primary: int, replica: int}> */
    public function totals(): array
    {
        $commands = array_unique([...array_keys($this->primary), ...array_keys($this->replica)]);
        $totals = [];
        foreach ($commands as $command) {
            $totals[] = [
                'command' => $command,
                'primary' => $this->primary[$command] ?? 0,
                'replica' => $this->replica[$command] ?? 0,
            ];
        }

        usort($totals, static function (array $left, array $right): int {
            $leftCalls = $left['primary'] + $left['replica'];
            $rightCalls = $right['primary'] + $right['replica'];

            return $rightCalls <=> $leftCalls ?: strcmp($left['command'], $right['command']);
        });

        return $totals;
    }

    public function commands(): int
    {
        return count(array_unique([...array_keys($this->primary), ...array_keys($this->replica)]));
    }

    public function calls(): int
    {
        return array_sum($this->primary) + array_sum($this->replica);
    }
}
