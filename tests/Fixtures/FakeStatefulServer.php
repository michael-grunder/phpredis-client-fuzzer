<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

/**
 * A server-free keyspace shared by several fake clients, so a scenario peer is
 * a second connection to the same data. Keys carry a version counter, which is
 * what WATCH needs to decide whether a transaction still applies.
 */
final class FakeStatefulServer
{
    /** @var array<string, array{type: string, value: mixed}> */
    private array $entries = [];

    /** @var array<string, int> */
    private array $versions = [];

    /** @var array<string, true> */
    private array $touched = [];

    /** @return array{type: string, value: mixed}|null */
    public function entry(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    /** Every key the clients wrote to or deleted, whether it exists or not.
     *
     * @return list<string>
     */
    public function touchedKeys(): array
    {
        return array_keys($this->touched);
    }

    public function version(string $key): int
    {
        return $this->versions[$key] ?? 0;
    }

    public function write(string $key, string $type, mixed $value): void
    {
        $this->touched[$key] = true;
        $this->entries[$key] = ['type' => $type, 'value' => $value];
        $this->versions[$key] = $this->version($key) + 1;
    }

    public function delete(string $key): int
    {
        $this->touched[$key] = true;
        if (!isset($this->entries[$key])) {
            return 0;
        }
        unset($this->entries[$key]);
        $this->versions[$key] = $this->version($key) + 1;

        return 1;
    }
}
