<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester;

use Random\Randomizer;

/** O(N) construction and storage, O(1) draws using the alias method. @internal */
final class WeightedIndexSampler
{
    /** @var array<int, float> */
    private array $probability = [];
    /** @var array<int, int> */
    private array $alias = [];
    private int $count;

    /** @param list<float> $weights */
    public function __construct(array $weights)
    {
        if ($weights === []) {
            throw new \InvalidArgumentException('Weights cannot be empty');
        }
        foreach ($weights as $weight) {
            if (!is_finite($weight) || $weight < 0.0) {
                throw new \InvalidArgumentException('Weights must be finite and non-negative');
            }
        }
        if (!is_finite(array_sum($weights))) {
            throw new \InvalidArgumentException('Total weight must be finite');
        }
        $this->count = count($weights);
        $this->initialize($weights);
    }

    /** @param list<float> $weights */
    private function initialize(array $weights): void {
        $total = array_sum($weights);
        if ($total <= 0.0) {
            throw new \InvalidArgumentException('At least one item must have a positive weight');
        }

        $scaled = [];
        foreach ($weights as $weight) {
            $scaled[] = $weight > PHP_FLOAT_MAX / $this->count
                ? ($weight / $total) * $this->count
                : ($weight * $this->count) / $total;
        }

        $small = [];
        $large = [];

        $this->probability = array_fill(0, $this->count, 0.0);
        $this->alias = array_fill(0, $this->count, 0);

        foreach ($scaled as $i => $weight) {
            if ($weight < 1.0) {
                $small[] = $i;
            } else {
                $large[] = $i;
            }
        }

        while (!empty($small) && !empty($large)) {
            $small_idx = array_pop($small);
            $large_idx = array_pop($large);

            $this->probability[$small_idx] = $scaled[$small_idx];
            $this->alias[$small_idx] = $large_idx;

            $scaled[$large_idx] = ($scaled[$large_idx] +
                                   $scaled[$small_idx]) - 1.0;

            if ($scaled[$large_idx] < 1.0 - 1e-8) {
                $small[] = $large_idx;
            } else {
                $large[] = $large_idx;
            }
        }

        foreach (array_merge($small, $large) as $index) {
            $this->probability[$index] = 1.0;
        }
    }

    public function pick(?Randomizer $randomizer = null): int
    {
        // Preserve the command selector's historical MT sequence when no
        // dedicated stream is supplied.
        $column = $randomizer?->getInt(0, $this->count - 1) ?? mt_rand(0, $this->count - 1);
        $p = $randomizer === null
            ? mt_rand() / mt_getrandmax()
            : $randomizer->getInt(0, 9007199254740991) / 9007199254740992;

        return $p < $this->probability[$column] ? $column : $this->alias[$column];
    }
}
