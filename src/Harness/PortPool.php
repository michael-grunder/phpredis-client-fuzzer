<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Hands a Redis port to each new run from the `--port` list.
 *
 * With `cycle` selection the list is walked round-robin; with `random` a port
 * is drawn at random. When `--isolate-ports` is set a port in use by a running
 * job is never handed to a second job, so concurrent runs never share a
 * server; otherwise the same port may back several runs at once, which
 * deliberately exercises the shared-server edge cases.
 */
final class PortPool
{
    /** @var array<int, int> port => number of running jobs holding it */
    private array $busy = [];

    private int $cursor = 0;

    /** @param list<int> $ports */
    public function __construct(
        private readonly array $ports,
        private readonly string $select,
        private readonly bool $isolate,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->ports === [];
    }

    /** @return list<int> */
    public function all(): array
    {
        return $this->ports;
    }

    /** Whether {@see acquire()} would currently return a port. */
    public function hasFree(): bool
    {
        if ($this->ports === []) {
            return false;
        }
        if (!$this->isolate) {
            return true;
        }
        foreach ($this->ports as $port) {
            if (($this->busy[$port] ?? 0) === 0) {
                return true;
            }
        }

        return false;
    }

    public function acquire(): ?int
    {
        if ($this->ports === []) {
            return null;
        }

        $port = $this->select === 'random' ? $this->pickRandom() : $this->pickCycle();
        if ($port === null) {
            return null;
        }

        $this->busy[$port] = ($this->busy[$port] ?? 0) + 1;

        return $port;
    }

    public function release(int $port): void
    {
        if (!isset($this->busy[$port])) {
            return;
        }
        $this->busy[$port]--;
        if ($this->busy[$port] <= 0) {
            unset($this->busy[$port]);
        }
    }

    private function pickCycle(): ?int
    {
        $count = count($this->ports);
        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($this->cursor + $offset) % $count;
            $port = $this->ports[$index];
            if ($this->isolate && ($this->busy[$port] ?? 0) > 0) {
                continue;
            }
            $this->cursor = ($index + 1) % $count;

            return $port;
        }

        return null;
    }

    private function pickRandom(): ?int
    {
        $candidates = $this->isolate
            ? array_values(array_filter(
                $this->ports,
                fn (int $port): bool => ($this->busy[$port] ?? 0) === 0,
            ))
            : $this->ports;

        if ($candidates === []) {
            return null;
        }

        return $candidates[random_int(0, count($candidates) - 1)];
    }
}
