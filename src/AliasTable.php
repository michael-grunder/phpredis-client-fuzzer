<?php

namespace Mgrunder\RedisClientTester;

/** @template T of HasWeight */
class AliasTable {
    /** @var list<T> */
    private array $items = [];
    private WeightedIndexSampler $sampler;

    /** @param array<array-key, T> $items */
    public function __construct(array $items) {
        $weights = [];
        foreach ($items as $item) {
            $this->items[] = $item;
            $weights[] = $item->weight();
        }
        $this->sampler = new WeightedIndexSampler($weights);
    }

    /** @return T */
    public function pick(): HasWeight {
        return $this->items[$this->sampler->pick()];
    }
}
