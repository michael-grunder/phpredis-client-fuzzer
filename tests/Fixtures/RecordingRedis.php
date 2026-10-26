<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

use Redis;

/** A fake whose actual method entries can be compared with standalone replay. */
class RecordingRedis extends FakeStatefulRedis
{
    public string $identity = 'reference';
    public bool $throwOnExec = false;

    public function getHost(): string
    {
        return $this->identity;
    }

    /** @param array<string, mixed>|null $context */
    public function connect(
        string $host, int $port = 6379, float $timeout = 0,
        ?string $persistent_id = null, int $retry_interval = 0,
        float $read_timeout = 0, ?array $context = null,
    ): bool {
        $this->identity = $host;
        return true;
    }

    public function failExec(): void
    {
        InvocationTrace::record($this->identity, __FUNCTION__, []);
        $this->throwOnExec = true;
    }

    public function referencePair(int &$first, int &$second): void
    {
        InvocationTrace::record($this->identity, __FUNCTION__, [$first, $second]);
        $first++;
        $second *= 2;
        InvocationTrace::$referenceResults[] = [$first, $second];
    }

    /** @param array<int|string, mixed>|int|null $options */
    public function set(string $key, mixed $value, mixed $options = null): Redis|string|bool
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::set($key, $value, $options);
    }

    public function get(string $key): mixed
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::get($key);
    }

    public function multi(int $value = Redis::MULTI): Redis|bool
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::multi($value);
    }

    /** @return Redis|array<int, mixed>|false */
    public function exec(): Redis|array|false
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        if ($this->throwOnExec) {
            throw new \TypeError('fake EXEC failure');
        }
        return parent::exec(...func_get_args());
    }

    public function discard(): Redis|bool
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::discard(...func_get_args());
    }

    public function getMode(): int
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::getMode(...func_get_args());
    }

    public function getLastError(): ?string
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::getLastError(...func_get_args());
    }

    public function clearLastError(): bool
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::clearLastError(...func_get_args());
    }

    /** @param array<int|string, mixed>|string $key */
    public function watch(array|string $key, string ...$other_keys): Redis|bool
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::watch($key, ...$other_keys);
    }

    public function unwatch(): Redis|bool
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::unwatch(...func_get_args());
    }

    public function close(): bool
    {
        InvocationTrace::record($this->identity, __FUNCTION__, func_get_args());
        return parent::close(...func_get_args());
    }

}
