<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * An explicitly seeded generator private to one testcase stream. It never
 * touches the process-global `mt_rand()` state, so generation is replayable
 * no matter what else runs in the process.
 */
final class Rng
{
    private readonly Randomizer $random;

    public function __construct(int $seed)
    {
        $this->random = new Randomizer(new Xoshiro256StarStar(self::expand($seed)));
    }

    /** An independent stream derived from $seed and a label. */
    public static function derive(int $seed, string $label): self
    {
        return new self(self::mix($seed, $label));
    }

    /** A non-negative seed derived from $seed and a label. */
    public static function mix(int $seed, string $label): int
    {
        $hash = unpack('J', hash('sha256', $seed . "\0" . $label, true));
        $value = is_array($hash) && is_int($hash[1] ?? null) ? $hash[1] : 0;

        return $value & PHP_INT_MAX;
    }

    private static function expand(int $seed): string
    {
        // Keep the original salt so saved seeds retain their command sequence.
        return hash('sha256', 'phpredis-fuzz-diff:' . $seed, true);
    }

    public function int(int $min, int $max): int
    {
        return $min >= $max ? $min : $this->random->getInt($min, $max);
    }

    public function chance(float $probability): bool
    {
        if ($probability <= 0.0) {
            return false;
        }
        if ($probability >= 1.0) {
            return true;
        }

        return $this->random->getInt(0, 999_999) < (int) ($probability * 1_000_000);
    }

    /**
     * @template T
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    /**
     * @template T of array-key
     * @param non-empty-array<T, int> $weights
     * @return T
     */
    public function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = $this->random->getInt(1, max(1, $total));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    public function bytes(int $length): string
    {
        return $length <= 0 ? '' : $this->random->getBytes($length);
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return $this->random->shuffleArray($items);
    }
}
