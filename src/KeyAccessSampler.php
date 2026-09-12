<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/** Seeded key profiles shared across type/shard namespaces. @internal */
final class KeyAccessSampler
{
    private int $keys = 0;
    private WeightedIndexSampler $neutral;
    private WeightedIndexSampler $reads;
    private WeightedIndexSampler $writes;
    private Randomizer $access;

    /** @param list<KeyReadGroup> $readGroups */
    public function __construct(
        private readonly int $seed,
        private readonly float $skew = 0.0,
        private readonly array $readGroups = [],
    ) {
        self::validate($skew, $readGroups);
    }

    /** @param list<KeyReadGroup> $readGroups */
    public static function validate(float $skew, array $readGroups): void
    {
        if (!is_finite($skew) || $skew < 0.0) {
            throw new \InvalidArgumentException('keySkew must be finite and non-negative');
        }
        $total = 0.0;
        foreach ($readGroups as $group) {
            $total += $group->weight;
        }
        if (!is_finite($total)) {
            throw new \InvalidArgumentException('Total key read group weight must be finite');
        }
    }

    public function pick(int $keys, int $flags): int
    {
        if ($keys !== $this->keys) {
            $this->initialize($keys);
        }

        // Mutation takes precedence over READ, including commands that return
        // a value while changing the key. Unclassified commands use popularity.
        $sampler = match (true) {
            ($flags & (Command::WRITE | Command::DELETE)) !== 0 => $this->writes,
            ($flags & Command::READ) !== 0 => $this->reads,
            default => $this->neutral,
        };

        return $sampler->pick($this->access);
    }

    private function initialize(int $keys): void
    {
        if ($keys < 1) {
            throw new \InvalidArgumentException('Key count must be positive');
        }
        $ranks = range(1, $keys);
        $rankRandom = $this->randomizer('ranks');
        for ($i = $keys - 1; $i > 0; $i--) {
            $j = $rankRandom->getInt(0, $i);
            [$ranks[$i], $ranks[$j]] = [$ranks[$j], $ranks[$i]];
        }
        $preferences = $this->randomizer('preferences');
        $groups = $this->readGroups === [] ? null : new WeightedIndexSampler(array_map(
            static fn (KeyReadGroup $group): float => $group->weight,
            $this->readGroups,
        ));
        $neutral = [];
        $reads = [];
        $writes = [];
        foreach ($ranks as $rank) {
            $popularity = 1.0 / ($rank ** $this->skew);
            $affinity = $groups === null ? 0.5 : $this->readGroups[$groups->pick($preferences)]->readAffinity;
            $neutral[] = $popularity;
            $reads[] = $popularity * $affinity;
            $writes[] = $popularity * (1.0 - $affinity);
        }
        $this->neutral = new WeightedIndexSampler($neutral);
        // Without read groups, all three distributions are identical.
        $this->reads = $groups === null ? $this->neutral : new WeightedIndexSampler($reads);
        $this->writes = $groups === null ? $this->neutral : new WeightedIndexSampler($writes);
        $this->access = $this->randomizer('access');
        $this->keys = $keys;
    }

    private function randomizer(string $stream): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash(
            'sha256',
            $this->seed . "\0key-access-v1\0" . $stream,
            true,
        )));
    }
}
