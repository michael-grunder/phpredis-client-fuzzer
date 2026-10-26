<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Values;

class SerializedValue
{
    public function __construct(public string $payload, public string $behavior = 'ordinary')
    {
    }

    /** @return array{payload: string, behavior: string} */
    public function __serialize(): array
    {
        if ($this->behavior === 'throw-serialize') {
            throw new \RuntimeException('Tester serialization callback');
        }

        return ['payload' => $this->payload, 'behavior' => $this->behavior];
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        if (!isset($data['payload'], $data['behavior']) || !is_string($data['payload']) || !is_string($data['behavior'])) {
            throw new \UnexpectedValueException('Malformed fuzzer fixture');
        }
        $this->payload = $data['payload'];
        $this->behavior = $data['behavior'];
        if ($this->behavior === 'throw-unserialize') {
            throw new \RuntimeException('Tester deserialization callback');
        }
    }
}
