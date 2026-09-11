<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures;

/**
 * A cluster client whose masters are separate FakeEvilrespProxy connections,
 * one of which can be made unreachable.
 */
final class FakeEvilrespCluster extends \RedisCluster
{
    /** @var list<list<mixed>> */
    public array $sent = [];

    public ?string $unreachable = null;

    /** @var array<string, FakeEvilrespProxy> */
    private array $proxies = [];

    /** @param list<array{string, int}> $masters */
    public function __construct(private readonly array $masters)
    {
    }

    /** @return list<array{string, int}> */
    public function _masters(): array
    {
        return $this->masters;
    }

    /** @param array{string, int}|string $key_or_address */
    public function rawCommand(array|string $key_or_address, string $cmd, mixed ...$args): mixed
    {
        $arguments = [$cmd, ...array_values($args)];
        $this->sent[] = [$key_or_address, ...$arguments];
        $address = is_array($key_or_address) ? "{$key_or_address[0]}:{$key_or_address[1]}" : $key_or_address;
        if ($address === $this->unreachable) {
            throw new \RedisClusterException('Unable to send command to the specified node');
        }
        $this->proxies[$address] ??= new FakeEvilrespProxy();

        return $this->proxies[$address]->answer(FakeEvilrespProxy::subcommand($arguments));
    }

    public function getMode(): int
    {
        return \Redis::ATOMIC;
    }

    public function getLastError(): ?string
    {
        return null;
    }
}
