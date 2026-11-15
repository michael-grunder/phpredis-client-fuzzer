<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Compatibility;

/** @phpstan-import-type Tape from Recording */
final class Minimizer
{
    /** Remove chunks, then individual calls, retaining the original failure signature.
     * @param Tape $tape
     * @param \Closure(Tape): bool $matches
     * @return Tape
     */
    public static function reduce(array $tape, \Closure $matches, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        for ($chunk = max(1, intdiv(count($tape['calls']), 2)); $chunk >= 1; $chunk = intdiv($chunk, 2)) {
            for ($offset = 0; $offset < count($tape['calls']) && microtime(true) < $deadline;) {
                $candidate = $tape;
                array_splice($candidate['calls'], $offset, $chunk);
                if ($candidate['calls'] === []) {
                    break;
                }
                if ($matches($candidate)) {
                    $tape = $candidate;
                } else {
                    $offset += $chunk;
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        return $tape;
    }
}
