<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

use Redis;

/**
 * A server-free client that behaves the way the stateful scenarios expect a
 * correct client to behave: MULTI and PIPELINE queueing, queue acknowledgement
 * with the client object, WATCH versions, queue-time and execution-time errors,
 * local argument rejection, and a lazy reconnect after close().
 *
 * The public flags deliberately break one of those properties at a time, so a
 * regression test can prove a scenario detects that defect.
 */
class FakeStatefulRedis extends Redis
{
    /** Serialize queued values at EXEC time instead of at queue time. */
    public bool $lazySerialization = false;

    /** Answer commands after a close() with replies owed to the old queue. */
    public bool $staleRepliesAfterClose = false;

    /** Keep the transaction mode across close(). */
    public bool $keepModeOnClose = false;

    /** Let EXEC run even though a command was rejected as it was queued. */
    public bool $abortsAfterQueueError = true;

    private int $mode = Redis::ATOMIC;

    /** @var list<\Closure(): mixed> */
    private array $queue = [];

    /** @var list<bool> */
    private array $stale = [];

    /** @var array<string, int> */
    private array $watched = [];

    private bool $aborted = false;

    private ?string $lastError = null;

    private bool $connected = true;

    public function __construct(
        private readonly FakeStatefulServer $server = new FakeStatefulServer(),
        private readonly int $serializer = Redis::SERIALIZER_NONE,
    ) {
    }

    public function server(): FakeStatefulServer
    {
        return $this->server;
    }

    /* ---------------------------------------------------------------- keys */

    /** @param array<int|string, mixed>|int|null $options */
    public function set(string $key, mixed $value, mixed $options = null): Redis|string|bool
    {
        $packed = $this->lazySerialization ? null : $this->pack($value);

        return $this->dispatch(function () use ($key, $value, $packed): bool {
            $this->server->write($key, 'string', $packed ?? $this->pack($value));

            return true;
        });
    }

    public function get(string $key): mixed
    {
        if ($this->stale !== []) {
            /* The reply owed to a command from the queue that was dropped. */
            return array_shift($this->stale);
        }

        return $this->dispatch(function () use ($key): mixed {
            $entry = $this->server->entry($key);
            if ($entry === null) {
                return false;
            }
            if ($entry['type'] !== 'string') {
                return $this->wrongType();
            }

            return $this->unpack($entry['value']);
        });
    }

    /** @param array<int|string, mixed>|string $key */
    public function del(array|string $key, string ...$other_keys): Redis|int|false
    {
        $keys = $this->names($key, array_values($other_keys));

        return $this->dispatch(function () use ($keys): int {
            $removed = 0;
            foreach ($keys as $name) {
                $removed += $this->server->delete($name);
            }

            return $removed;
        });
    }

    public function incr(string $key, int $by = 1): Redis|int|false
    {
        return $this->dispatch(function () use ($key, $by): int|false {
            $entry = $this->server->entry($key);
            if ($entry !== null && $entry['type'] !== 'string') {
                return $this->wrongType();
            }
            $current = $entry === null || !is_scalar($entry['value']) ? 0 : (int) $entry['value'];
            $this->server->write($key, 'string', (string) ($current + $by));

            return $current + $by;
        });
    }

    public function hGetAll(string $key): Redis|array|false
    {
        return $this->dispatch(function () use ($key): array|false {
            $entry = $this->server->entry($key);
            if ($entry === null) {
                return [];
            }
            if ($entry['type'] !== 'hash') {
                return $this->wrongType();
            }

            return is_array($entry['value']) ? $entry['value'] : [];
        });
    }

    public function rPush(string $key, mixed ...$elements): Redis|int|false
    {
        return $this->dispatch(function () use ($key, $elements): int|false {
            $entry = $this->server->entry($key);
            if ($entry !== null && $entry['type'] !== 'list') {
                return $this->wrongType();
            }
            $list = $entry !== null && is_array($entry['value']) ? $entry['value'] : [];
            foreach ($elements as $element) {
                $list[] = is_scalar($element) ? (string) $element : serialize($element);
            }
            $this->server->write($key, 'list', $list);

            return count($list);
        });
    }

    public function lLen(string $key): Redis|int|false
    {
        return $this->dispatch(function () use ($key): int|false {
            $entry = $this->server->entry($key);
            if ($entry === null) {
                return 0;
            }
            if ($entry['type'] !== 'list') {
                return $this->wrongType();
            }

            return is_array($entry['value']) ? count($entry['value']) : 0;
        });
    }

    /** @param array<int|string, mixed> $keys */
    public function sIntercard(array $keys, int $limit = -1): Redis|int|false
    {
        /* Rejected by the argument parser: nothing reaches the socket, and
           nothing is queued. */
        if (func_num_args() === 2 && $limit < 0) {
            trigger_error('LIMIT cannot be negative', E_USER_WARNING);

            return false;
        }

        return $this->dispatch(static fn (): int => 0);
    }

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        /* GET with no arguments is rejected by the server as it is queued. */
        if (strtolower($command) === 'get' && $args === []) {
            $this->lastError = "ERR wrong number of arguments for 'get' command";
            $this->aborted = $this->abortsAfterQueueError && $this->mode === Redis::MULTI;

            return false;
        }

        return $this->dispatch(static fn (): bool => true);
    }

    /* --------------------------------------------------------- transactions */

    public function multi(int $value = Redis::MULTI): Redis|bool
    {
        $this->reconnect();
        if ($this->mode !== Redis::ATOMIC) {
            return false;
        }
        $this->mode = $value === Redis::PIPELINE ? Redis::PIPELINE : Redis::MULTI;
        $this->queue = [];
        $this->aborted = false;

        return $this;
    }

    /** @return Redis|array<int, mixed>|false */
    public function exec(): Redis|array|false
    {
        $this->reconnect();
        if ($this->mode === Redis::ATOMIC) {
            $this->lastError = 'ERR EXEC without MULTI';

            return false;
        }

        $queue = $this->queue;
        $pipeline = $this->mode === Redis::PIPELINE;
        $aborted = $this->aborted;
        $watchChanged = $this->watchChanged();
        $this->queue = [];
        $this->aborted = false;
        $this->mode = Redis::ATOMIC;
        $this->watched = [];

        if (!$pipeline && $aborted) {
            $this->lastError = 'EXECABORT Transaction discarded because of previous errors.';

            return false;
        }
        if (!$pipeline && $watchChanged) {
            return false;
        }

        $replies = [];
        foreach ($queue as $operation) {
            $replies[] = $operation();
        }

        return $replies;
    }

    public function discard(): Redis|bool
    {
        $this->reconnect();
        if ($this->mode === Redis::ATOMIC) {
            return false;
        }
        $this->queue = [];
        $this->aborted = false;
        $this->mode = Redis::ATOMIC;
        $this->watched = [];

        return true;
    }

    /** @param array<int|string, mixed>|string $key */
    public function watch(array|string $key, string ...$other_keys): Redis|bool
    {
        $this->reconnect();
        foreach ($this->names($key, array_values($other_keys)) as $name) {
            $this->watched[$name] = $this->server->version($name);
        }

        return true;
    }

    public function unwatch(): Redis|bool
    {
        $this->reconnect();
        $this->watched = [];

        return true;
    }

    /* ------------------------------------------------------------ transport */

    public function ping(?string $message = null): Redis|string|bool
    {
        return $this->dispatch(static fn (): string => $message ?? 'PONG');
    }

    public function close(): bool
    {
        if ($this->staleRepliesAfterClose) {
            /* A client that keeps the callbacks owed to the dropped queue. */
            $this->stale = array_map(static fn (): bool => true, $this->queue);
        }
        $this->connected = false;
        $this->queue = [];
        $this->aborted = false;
        $this->watched = [];
        if (!$this->keepModeOnClose) {
            $this->mode = Redis::ATOMIC;
        }

        return true;
    }

    /** @param array<string, mixed>|null $context */
    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0,
        ?array $context = null,
    ): bool {
        $this->connected = true;

        return true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getHost(): string
    {
        return 'fake-stateful';
    }

    public function getPort(): int
    {
        return 6379;
    }

    public function getMode(): int
    {
        return $this->mode;
    }

    public function getOption(int $option): mixed
    {
        return $option === Redis::OPT_SERIALIZER ? $this->serializer : 0;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function clearLastError(): bool
    {
        $this->lastError = null;

        return true;
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * @template TReply
     * @param \Closure(): TReply $operation
     * @return TReply|static
     */
    private function dispatch(\Closure $operation): mixed
    {
        $this->reconnect();
        if ($this->mode === Redis::ATOMIC) {
            return $operation();
        }
        $this->queue[] = $operation;

        return $this;
    }

    private function reconnect(): void
    {
        $this->connected = true;
    }

    private function wrongType(): false
    {
        $this->lastError = 'WRONGTYPE Operation against a key holding the wrong kind of value';

        return false;
    }

    private function watchChanged(): bool
    {
        foreach ($this->watched as $key => $version) {
            if ($this->server->version($key) !== $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed>|string $key
     * @param list<string> $others
     * @return list<string>
     */
    private function names(array|string $key, array $others): array
    {
        $names = is_array($key) ? array_values($key) : [$key, ...$others];

        return array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            $names,
        );
    }

    private function pack(mixed $value): string
    {
        if ($this->serializer !== Redis::SERIALIZER_NONE) {
            return serialize($value);
        }

        return is_scalar($value) ? (string) $value : serialize($value);
    }

    private function unpack(mixed $value): mixed
    {
        if ($this->serializer === Redis::SERIALIZER_NONE || !is_string($value)) {
            return $value;
        }
        $decoded = @unserialize($value);

        return $decoded === false && $value !== serialize(false) ? $value : $decoded;
    }
}
