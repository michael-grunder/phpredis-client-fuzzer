<?php

namespace Mgrunder\PhpredisCommandFuzzer;

/** @template T of HasWeight */
class AliasTable {
    /** @var list<T> */
    private array $items = [];
    /** @var array<int, float> */
    private array $probability = [];
    /** @var array<int, int> */
    private array $alias = [];
    private int $count = 0;

    /** @param array<array-key, T> $items */
    public function __construct(array $items) {
        if (empty($items))
            throw new \Exception("Items array cannot be empty.");

        $this->count = count($items);

        $weights = [];
        foreach ($items as $item) {
            if ($item->weight() < 0.0) {
                throw new \InvalidArgumentException('Item weights cannot be negative');
            }
            $this->items[] = $item;
            $weights[] = $item->weight();
        }

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
            $scaled[] = ($weight * $this->count) / $total;
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

    /** @return T */
    public function pick(): HasWeight {
        $column = mt_rand(0, $this->count - 1);

        $p = $this->randomFloat();
        if ($p < $this->probability[$column])
            return $this->items[$column];

        return $this->items[$this->alias[$column]];
    }

    private function randomFloat(): float {
        return mt_rand() / mt_getrandmax();
    }
}
