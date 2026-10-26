<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

/**
 * A standalone client in front of a FakeEvilrespProxy connection: STATUS can
 * be preceded by leftover bytes of an earlier reply, close() reconnects to an
 * idle connection, and the client can be made to reject or throw.
 */
final class FakeEvilrespRedis extends \Redis
{
    /** @var list<list<mixed>> */
    public array $sent = [];

    public FakeEvilrespProxy $proxy;

    /** @var list<string> */
    public array $garbage = [];

    public int $closed = 0;

    public int $mode = \Redis::ATOMIC;

    public ?string $rejection = null;

    public ?\Throwable $throwing = null;

    private ?string $lastError = null;

    public function __construct()
    {
        $this->proxy = new FakeEvilrespProxy();
    }

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        $arguments = [$command, ...array_values($args)];
        $this->sent[] = $arguments;
        if ($this->throwing !== null) {
            throw $this->throwing;
        }
        if ($this->rejection !== null) {
            $this->lastError = $this->rejection;

            return false;
        }
        if ($this->garbage !== []) {
            return array_shift($this->garbage);
        }

        return $this->proxy->answer(FakeEvilrespProxy::subcommand($arguments));
    }

    public function close(): bool
    {
        $this->closed++;
        $this->garbage = [];
        $this->proxy = new FakeEvilrespProxy();

        return true;
    }

    public function getMode(): int
    {
        return $this->mode;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getOption(int $option): mixed
    {
        return false;
    }

    public function getAuth(): mixed
    {
        return null;
    }
}
