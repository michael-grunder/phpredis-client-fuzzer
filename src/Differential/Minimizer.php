<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

/**
 * Shrinks a diverging testcase while it keeps diverging the same way.
 *
 * Every candidate is re-executed against both clients and accepted only when
 * its {@see RunPair::signature()} equals the original's, so the shrinker
 * cannot drift from one bug to a different one. In order, it:
 *
 *   1. cuts the test sequence after the first diverging operation,
 *   2. removes contiguous ranges of test operations, halving the range size,
 *   3. removes setup commands the same way,
 *   4. drops elements from multi-element setup commands,
 *   5. simplifies operation arguments (shorter strings, fewer list items),
 *   6. resets client knobs (serializer, compression, Relay cache) to defaults.
 *
 * The result is verified several more times before it is trusted.
 */
final class Minimizer
{
    private float $deadline = 0.0;

    private int $trials = 0;

    public function __construct(
        private readonly DifferentialExecutor $executor,
        private readonly float $budgetSeconds = 60.0,
        private readonly int $verifyRuns = 3,
    ) {
    }

    public function trials(): int
    {
        return $this->trials;
    }

    /** @return TestCase|null The smaller case, or null when nothing smaller verified. */
    public function minimize(RunPair $original): ?TestCase
    {
        $signature = $original->signature();
        if ($signature === null) {
            return null;
        }

        $this->deadline = microtime(true) + $this->budgetSeconds;
        $this->trials = 0;
        $case = $original->case;

        $first = $original->firstIndex();
        if ($first !== null && $first + 1 < count($case->test)) {
            $candidate = $case->withTest(array_slice($case->test, 0, $first + 1));
            if ($this->reproduces($candidate, $signature)) {
                $case = $candidate;
            }
        }

        $case = $this->reduceList($case, $signature, true);
        $case = $this->reduceList($case, $signature, false);
        $case = $this->reduceSetupElements($case, $signature);
        $case = $this->simplifyArguments($case, $signature);
        $case = $this->resetKnobs($case, $signature);

        if ($case === $original->case) {
            return null;
        }
        for ($i = 0; $i < $this->verifyRuns; $i++) {
            if (!$this->reproduces($case, $signature, ignoreDeadline: true)) {
                return null;
            }
        }

        return $case;
    }

    private function reproduces(TestCase $case, string $signature, bool $ignoreDeadline = false): bool
    {
        if (!$ignoreDeadline && microtime(true) > $this->deadline) {
            return false;
        }
        if (!self::wellFormed($case)) {
            return false;
        }

        $this->trials++;
        try {
            return $this->executor->run($case)->signature() === $signature;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether MULTI/PIPELINE blocks never nest. The generator only emits
     * closed blocks (a --steps cut may leave the last one open); removing an
     * EXEC must not produce MULTI..PIPELINE, which Relay reports with an
     * uncatchable fatal error rather than a reply.
     */
    public static function wellFormed(TestCase $case): bool
    {
        $open = false;
        foreach ($case->test as [$method]) {
            $method = strtolower($method);
            if ($method === 'multi' || $method === 'pipeline') {
                if ($open) {
                    return false;
                }
                $open = true;
            } elseif ($method === 'exec' || $method === 'discard') {
                $open = false;
            }
        }

        return true;
    }

    /** @phpstan-impure */
    private function expired(): bool
    {
        return microtime(true) > $this->deadline;
    }

    /**
     * Delta-debugging style range removal over the test sequence ($test) or
     * the setup commands (!$test).
     */
    private function reduceList(TestCase $case, string $signature, bool $test): TestCase
    {
        $count = $test ? count($case->test) : count($case->setup);
        $chunk = max(1, intdiv($count + 1, 2));

        while (!$this->expired()) {
            $start = 0;
            while ($start < ($test ? count($case->test) : count($case->setup)) && !$this->expired()) {
                if ($test) {
                    $remaining = array_merge(array_slice($case->test, 0, $start), array_slice($case->test, $start + $chunk));
                    $candidate = $remaining === [] ? null : $case->withTest($remaining);
                } else {
                    $candidate = $case->withSetup(array_merge(array_slice($case->setup, 0, $start), array_slice($case->setup, $start + $chunk)));
                }
                if ($candidate !== null && $this->reproduces($candidate, $signature)) {
                    $case = $candidate;
                } else {
                    $start += $chunk;
                }
            }
            if ($chunk === 1) {
                break;
            }
            $chunk = intdiv($chunk, 2);
        }

        return $case;
    }

    /** Drop members of HSET/ZADD (pairs) and RPUSH/SADD (singles) setup commands. */
    private function reduceSetupElements(TestCase $case, string $signature): TestCase
    {
        foreach (array_keys($case->setup) as $index) {
            $stride = match (strtoupper($case->setup[$index][0])) {
                'HSET', 'ZADD' => 2,
                'RPUSH', 'SADD' => 1,
                default => 0,
            };
            if ($stride === 0) {
                continue;
            }

            $position = 2;
            while ($position < count($case->setup[$index]) && count($case->setup[$index]) - $stride >= 2 + $stride && !$this->expired()) {
                $command = $case->setup[$index];
                array_splice($command, $position, $stride);
                $setup = $case->setup;
                $setup[$index] = $command;
                $candidate = $case->withSetup($setup);
                if ($this->reproduces($candidate, $signature)) {
                    $case = $candidate;
                } else {
                    $position += $stride;
                }
            }
        }

        return $case;
    }

    private function simplifyArguments(TestCase $case, string $signature): TestCase
    {
        foreach (array_keys($case->test) as $opIndex) {
            foreach (array_keys($case->test[$opIndex][1]) as $argIndex) {
                if ($this->expired()) {
                    return $case;
                }
                foreach ($this->simpler($case->test[$opIndex][1][$argIndex], $case->prefix) as $replacement) {
                    $test = $case->test;
                    $args = $test[$opIndex][1];
                    $args[$argIndex] = $replacement;
                    $test[$opIndex] = [$test[$opIndex][0], array_values($args), $test[$opIndex][2]];
                    $candidate = $case->withTest($test);
                    if ($this->reproduces($candidate, $signature)) {
                        $case = $candidate;
                        break;
                    }
                }
            }
        }

        return $case;
    }

    /**
     * Candidate replacements for one argument, simplest first. Keys (which
     * must stay inside the prefix) are never shortened below the prefix.
     *
     * @return list<mixed>
     */
    private function simpler(mixed $value, string $prefix): array
    {
        if (is_string($value) && strlen($value) > 8 && !str_starts_with($value, $prefix)) {
            return [substr($value, 0, 8), substr($value, 0, intdiv(strlen($value), 2))];
        }
        if (is_array($value) && array_is_list($value) && count($value) > 1) {
            return [array_slice($value, 0, 1), array_slice($value, 0, intdiv(count($value), 2))];
        }

        return [];
    }

    private function resetKnobs(TestCase $case, string $signature): TestCase
    {
        $defaults = [
            ['serializer' => 'none'] + $case->options,
            ['compression' => 'none'] + $case->options,
            ['relay_cache' => true] + $case->options,
        ];
        foreach ($defaults as $options) {
            $options = [
                'serializer' => $options['serializer'],
                'compression' => $options['compression'],
                'relay_cache' => $options['relay_cache'],
            ];
            if ($options === $case->options || $this->expired()) {
                continue;
            }
            $candidate = $case->withOptions($options);
            if ($this->reproduces($candidate, $signature)) {
                $case = $candidate;
            }
        }

        return $case;
    }
}
