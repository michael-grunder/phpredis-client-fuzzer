<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

final readonly class Node
{
    public function __construct(
        public string $host,
        public int $port,
        public NodeRole $role,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('A Redis node host cannot be empty');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('A Redis node port must be between 1 and 65535');
        }
    }

    public static function fromAddress(string $address, NodeRole $role): self
    {
        $address = trim(explode('@', explode(',', $address, 2)[0], 2)[0]);
        if (preg_match('/^\[([^]]+)]:(\d+)$/', $address, $matches) === 1) {
            return new self($matches[1], self::port($matches[2]), $role);
        }

        $separator = strrpos($address, ':');
        if ($separator === false) {
            throw new \InvalidArgumentException("Redis address must be HOST:PORT: {$address}");
        }

        $host = substr($address, 0, $separator);
        $port = substr($address, $separator + 1);
        if ($host === '' || $port === '') {
            throw new \InvalidArgumentException("Redis address must be HOST:PORT: {$address}");
        }

        return new self($host, self::port($port), $role);
    }

    public function key(): string
    {
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;

        return "{$host}:{$this->port}";
    }

    private static function port(string $value): int
    {
        if ($value === '' || !ctype_digit($value)) {
            throw new \InvalidArgumentException("Invalid Redis port: {$value}");
        }

        $port = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($port)) {
            throw new \InvalidArgumentException("Invalid Redis port: {$value}");
        }

        return $port;
    }
}
