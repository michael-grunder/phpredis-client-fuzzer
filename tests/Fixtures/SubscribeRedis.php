<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

/** Server-free simulation of Relay's queued subscription confirmations and messages. */
final class SubscribeRedis extends RecordingRedis
{
    /** @var array<string, callable(self, string, ?string): void> */
    private array $subscriptions = [];
    /** @var list<array{string, ?string}> */
    private array $messages = [];
    private bool $dispatching = false;
    public int $callbacks = 0;
    public int $commandCalls = 0;
    public bool $commandsInsideCallback = true;
    public bool $throwOnGet = false;
    /** @var list<array{string, list<string>}> */
    public array $subscriptionCalls = [];

    public function setOption(int $option, mixed $value): bool { return true; }

    /**
     * @param list<string> $channels
     * @param callable(self, string, ?string): void $cb
     */
    public function subscribe(array $channels, callable $cb): bool
    {
        $this->subscriptionCalls[] = ['subscribe', $channels];
        foreach ($channels as $channel) {
            $this->subscriptions[$channel] = $cb;
            $this->messages[] = [$channel, null];
        }
        if ($this->dispatching) return true;
        $this->dispatching = true;
        try {
            while ($this->messages !== [] && $this->subscriptions !== []) {
                [$channel, $message] = array_shift($this->messages);
                $callback = $this->subscriptions[$channel] ?? null;
                if ($callback !== null) {
                    if (++$this->callbacks > 100) throw new \LogicException('Callback loop did not stop');
                    $callback($this, $channel, $message);
                }
            }
        } finally {
            $this->dispatching = false;
        }
        return true;
    }

    /** @param list<string> $channels */
    public function unsubscribe(array $channels = []): bool
    {
        $this->subscriptionCalls[] = ['unsubscribe', $channels];
        if ($channels === []) $this->subscriptions = [];
        foreach ($channels as $channel) unset($this->subscriptions[$channel]);
        return true;
    }

    public function publish(string $channel, string $message): int
    {
        $this->subscriptionCalls[] = ['publish', [$channel, $message]];
        if (!isset($this->subscriptions[$channel])) return 0;
        $this->messages[] = [$channel, $message];
        return 1;
    }

    public function get(string $key): mixed
    {
        $this->commandCalls++;
        $this->commandsInsideCallback = $this->commandsInsideCallback && $this->dispatching;
        if ($this->throwOnGet) throw new \TypeError('fake callback GET failure');
        return parent::get($key);
    }

    /** @param array<int|string, mixed>|int|null $options */
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
    {
        $this->commandCalls++;
        $this->commandsInsideCallback = $this->commandsInsideCallback && $this->dispatching;
        return parent::set($key, $value, $options);
    }
}
