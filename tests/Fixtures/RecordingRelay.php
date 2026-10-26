<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

/** Loaded only by tests that have Relay installed. No network methods are used. */
final class RecordingRelay extends \Relay\Relay
{
    /** @var list<string> */
    private array $replies = [];

    public function __construct() {}

    /** @param list<string> $replies */
    public function replies(array $replies): void
    {
        InvocationTrace::record('subject', __FUNCTION__, [$replies]);
        $this->replies = $replies;
    }

    public function get(mixed $key): mixed
    {
        InvocationTrace::record('subject', __FUNCTION__, [$key]);
        $reply = array_shift($this->replies) ?? 'current';
        if ($reply === 'throw') {
            throw new \TypeError('fake warm read failure');
        }
        return $reply;
    }

    public function getMode(bool $masked = false): int
    {
        InvocationTrace::record('subject', __FUNCTION__, func_get_args());
        return \Redis::ATOMIC;
    }

    public function clearLastError(): bool
    {
        InvocationTrace::record('subject', __FUNCTION__, []);
        return true;
    }

    public function getLastError(): ?string
    {
        InvocationTrace::record('subject', __FUNCTION__, []);
        return null;
    }

    public function getOption(int $option): mixed { return 0; }
    public function getAuth(): mixed { return null; }
    public function getTimeout(): float { return 0.0; }
    public function getHost(): string { return 'subject'; }
    public function isConnected(): bool { return true; }

    /** @param array<string, mixed> $context */
    public function connect(
        string $host, int $port = 6379, float $timeout = 0.0,
        ?string $persistent_id = null, int $retry_interval = 0,
        float $read_timeout = 0.0, ?array $context = null, int $database = 0,
    ): bool { return true; }
}
