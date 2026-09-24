<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Differential;

/**
 * The knobs that shape generated differential testcases. Every value is part
 * of the reproduction recipe: the same seed with the same options yields the
 * same case.
 */
final readonly class GeneratorOptions
{
    public const ORDERS = ['random', 'phpredis-first', 'relay-first'];

    /** @var list<string> */
    public array $serializers;

    /** @var list<string> */
    public array $compressions;

    /** @var list<bool> */
    public array $relayCache;

    /**
     * @param int $steps Test operations per case. The generated sequence for a
     *        smaller value is always a prefix of the sequence for a larger
     *        one, which is what lets the harness reduce `--steps`.
     * @param int $keys Keys in the case's namespace.
     * @param int $members Maximum elements in a setup collection.
     * @param list<string> $serializers Chosen from per case.
     * @param list<string> $compressions Chosen from per case.
     * @param list<bool> $relayCache Relay OPT_USE_CACHE values chosen from per case.
     * @param list<string>|null $commands Enabled operation names, or null for the default catalog.
     */
    public function __construct(
        public int $steps = 20,
        public int $keys = 6,
        public int $members = 8,
        public float $wrongTypeChance = 0.08,
        public float $missingChance = 0.08,
        public float $edgeChance = 0.15,
        public float $txnChance = 0.05,
        public float $ttlChance = 0.15,
        public int $maxValueBytes = 1024,
        array $serializers = ['none'],
        array $compressions = ['none'],
        array $relayCache = [true],
        public string $order = 'random',
        public bool $allowFlush = false,
        public ?array $commands = null,
    ) {
        if ($steps < 1 || $steps > 100_000) {
            throw new \InvalidArgumentException('steps must be between 1 and 100000');
        }
        if ($keys < 1 || $keys > 10_000) {
            throw new \InvalidArgumentException('keys must be between 1 and 10000');
        }
        if ($members < 0 || $members > 10_000) {
            throw new \InvalidArgumentException('members must be between 0 and 10000');
        }
        foreach (['wrongTypeChance' => $wrongTypeChance, 'missingChance' => $missingChance, 'edgeChance' => $edgeChance, 'txnChance' => $txnChance, 'ttlChance' => $ttlChance] as $name => $chance) {
            if (!is_finite($chance) || $chance < 0.0 || $chance > 1.0) {
                throw new \InvalidArgumentException("{$name} must be between 0 and 1");
            }
        }
        if ($maxValueBytes < 1 || $maxValueBytes > 16 * 1024 * 1024) {
            throw new \InvalidArgumentException('max value bytes must be between 1 and 16777216');
        }
        if ($serializers === [] || $compressions === [] || $relayCache === []) {
            throw new \InvalidArgumentException('serializer, compression, and relay cache choices cannot be empty');
        }
        if (!in_array($order, self::ORDERS, true)) {
            throw new \InvalidArgumentException('order must be one of ' . implode(', ', self::ORDERS));
        }
        if ($commands !== null) {
            $unknown = array_diff($commands, CaseGenerator::operationNames());
            if ($unknown !== []) {
                throw new \InvalidArgumentException('unknown differential operations: ' . implode(', ', $unknown));
            }
            if ($commands === []) {
                throw new \InvalidArgumentException('the command filter selects no operations');
            }
        }

        $this->serializers = array_values(array_unique($serializers));
        $this->compressions = array_values(array_unique($compressions));
        $this->relayCache = array_values(array_unique($relayCache));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'steps' => $this->steps,
            'keys' => $this->keys,
            'members' => $this->members,
            'wrong_type_chance' => $this->wrongTypeChance,
            'missing_chance' => $this->missingChance,
            'edge_chance' => $this->edgeChance,
            'txn_chance' => $this->txnChance,
            'ttl_chance' => $this->ttlChance,
            'max_value_bytes' => $this->maxValueBytes,
            'serializers' => $this->serializers,
            'compressions' => $this->compressions,
            'relay_cache' => $this->relayCache,
            'order' => $this->order,
            'allow_flush' => $this->allowFlush,
            'commands' => $this->commands,
        ];
    }
}
