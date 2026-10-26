<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester;

/** A relative share of keys with a persistent read affinity. */
final readonly class KeyReadGroup implements \JsonSerializable
{
    public function __construct(public float $weight, public float $readAffinity)
    {
        if (!is_finite($weight) || $weight <= 0.0) {
            throw new \InvalidArgumentException('Key read group weight must be finite and positive');
        }
        if (!is_finite($readAffinity) || $readAffinity <= 0.0 || $readAffinity >= 1.0) {
            throw new \InvalidArgumentException('Key read affinity must be finite and strictly between 0 and 1');
        }
    }

    public static function parse(string $value): self
    {
        $parts = explode(':', $value);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            throw new \InvalidArgumentException('Key read group must be WEIGHT:AFFINITY');
        }

        return new self((float) $parts[0], (float) $parts[1]);
    }

    /** @return array{weight: float, readAffinity: float} */
    public function jsonSerialize(): array
    {
        return ['weight' => $this->weight, 'readAffinity' => $this->readAffinity];
    }
}
