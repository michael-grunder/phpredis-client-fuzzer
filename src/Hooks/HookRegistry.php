<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class HookRegistry implements InvocationHook
{
    /**
     * Lifecycle interfaces an object may implement, in the order
     * addLifecycleHook() registers them.
     *
     * @var list<class-string>
     */
    private const LIFECYCLE_HOOKS = [
        PostConstructorHook::class,
        PreCommandHook::class,
        PostCommandHook::class,
        PreDestructorHook::class,
    ];

    /** @var list<array{id: string, hook: InvocationHook}> */
    private array $hooks = [];

    /** @var list<array{id: string, hook: InvocationHook}> */
    private array $guards = [];

    /** @var array<string, list<array{id: string, listener: callable}>> */
    private array $listeners = [];

    /** @var array<string, array{count: int, reason: string}> */
    private array $rejections = [];

    /** @var list<array{path: string, sha256: string}> */
    private array $sources = [];

    /**
     * Clients already announced to PostConstructor listeners. A weak map so a
     * client the caller drops is not kept alive here, and so a later object
     * that reuses its handle is announced on its own.
     *
     * @var \WeakMap<object, true>
     */
    private \WeakMap $announced;

    public function __construct()
    {
        /** @var \WeakMap<object, true> $announced */
        $announced = new \WeakMap();
        $this->announced = $announced;
    }

    public function add(string $id, InvocationHook $hook): void
    {
        $this->assertAvailableId($id);

        $this->hooks[] = ['id' => $id, 'hook' => $hook];
    }

    /**
     * Add a final safety guard. Guards run after every ordinary hook has
     * transformed the invocation and may only allow or reject it.
     */
    public function addGuard(string $id, InvocationHook $guard): void
    {
        $this->assertAvailableId($id);

        $this->guards[] = ['id' => $id, 'hook' => $guard];
    }

    /**
     * Deny one exact runtime method and argument tuple.
     *
     * @param list<mixed> $arguments
     * @param list<class-string> $clientClasses Empty means every supported client.
     */
    public function deny(
        string $id,
        string $method,
        array $arguments,
        string $reason,
        array $clientClasses = [],
    ): void {
        $this->add(
            $id,
            new ExactInvocationDenyHook(
                $method,
                $arguments,
                $reason,
                $clientClasses,
            ),
        );
    }

    /** @param callable(ClientEvent): void $listener */
    public function onPostConstructor(string $id, callable $listener): void
    {
        $this->listen(HookEvent::PostConstructor, $id, $listener);
    }

    /** @param callable(PendingInvocation): void $listener */
    public function onPreCommand(string $id, callable $listener): void
    {
        $this->listen(HookEvent::PreCommand, $id, $listener);
    }

    /** @param callable(CompletedInvocation): void $listener */
    public function onPostCommand(string $id, callable $listener): void
    {
        $this->listen(HookEvent::PostCommand, $id, $listener);
    }

    /** @param callable(ClientEvent): void $listener */
    public function onPreDestructor(string $id, callable $listener): void
    {
        $this->listen(HookEvent::PreDestructor, $id, $listener);
    }

    /**
     * Register every lifecycle interface one object implements under a single
     * id. An object may implement any combination of them.
     */
    public function addLifecycleHook(string $id, object $hook): void
    {
        $registered = false;
        if ($hook instanceof PostConstructorHook) {
            $this->onPostConstructor($id, $hook->postConstructor(...));
            $registered = true;
        }
        if ($hook instanceof PreCommandHook) {
            $this->onPreCommand($id, $hook->preCommand(...));
            $registered = true;
        }
        if ($hook instanceof PostCommandHook) {
            $this->onPostCommand($id, $hook->postCommand(...));
            $registered = true;
        }
        if ($hook instanceof PreDestructorHook) {
            $this->onPreDestructor($id, $hook->preDestructor(...));
            $registered = true;
        }

        if (!$registered) {
            throw new \InvalidArgumentException(
                'A lifecycle hook must implement at least one of: '
                . implode(', ', self::LIFECYCLE_HOOKS),
            );
        }
    }

    public static function isLifecycleHook(object $hook): bool
    {
        foreach (self::LIFECYCLE_HOOKS as $interface) {
            if ($hook instanceof $interface) {
                return true;
            }
        }

        return false;
    }

    public function hasListeners(HookEvent $event): bool
    {
        return ($this->listeners[$event->value] ?? []) !== [];
    }

    /**
     * Announce a client exactly once, however many times the fuzzer and its
     * client factory see it.
     */
    public function dispatchPostConstructor(
        Redis|RedisCluster|Relay|Cluster $client,
        ?int $clientIndex = null,
    ): void {
        if (!$this->hasListeners(HookEvent::PostConstructor) || isset($this->announced[$client])) {
            return;
        }
        $this->announced[$client] = true;

        $this->dispatch(
            HookEvent::PostConstructor,
            new ClientEvent(HookEvent::PostConstructor, $client, $clientIndex),
        );
    }

    public function dispatchPreDestructor(
        Redis|RedisCluster|Relay|Cluster $client,
        ?int $clientIndex = null,
    ): void {
        $this->dispatch(
            HookEvent::PreDestructor,
            new ClientEvent(HookEvent::PreDestructor, $client, $clientIndex),
        );
    }

    public function dispatchPreCommand(PendingInvocation $invocation): void
    {
        $this->dispatch(HookEvent::PreCommand, $invocation);
    }

    public function dispatchPostCommand(CompletedInvocation $invocation): void
    {
        $this->dispatch(HookEvent::PostCommand, $invocation);
    }

    public function beforeInvocation(PendingInvocation $invocation): HookDecision
    {
        $current = $invocation;
        $replaced = false;
        foreach ($this->hooks as $registered) {
            try {
                $decision = $registered['hook']->beforeInvocation($current);
            } catch (\Throwable $throwable) {
                throw new HookExecutionFailed($registered['id'], $throwable);
            }
            if ($decision->action === HookAction::Allow) {
                continue;
            }
            if ($decision->action === HookAction::Reject) {
                $reason = $decision->reason ?? 'Rejected without a reason';
                $id = $registered['id'];
                $this->rejections[$id] ??= ['count' => 0, 'reason' => $reason];
                $this->rejections[$id]['count']++;
                $this->rejections[$id]['reason'] = $reason;

                return $decision;
            }

            $arguments = $decision->arguments;
            if ($arguments === null) {
                throw new \LogicException(
                    "Invocation hook {$registered['id']} returned replace without arguments",
                );
            }
            $current = $current->withArguments($arguments);
            $replaced = true;
        }

        foreach ($this->guards as $registered) {
            try {
                $decision = $registered['hook']->beforeInvocation($current);
            } catch (\Throwable $throwable) {
                throw new HookExecutionFailed($registered['id'], $throwable);
            }
            if ($decision->action === HookAction::Allow) {
                continue;
            }
            if ($decision->action === HookAction::Reject) {
                $reason = $decision->reason ?? 'Rejected without a reason';
                $id = $registered['id'];
                $this->rejections[$id] ??= ['count' => 0, 'reason' => $reason];
                $this->rejections[$id]['count']++;
                $this->rejections[$id]['reason'] = $reason;

                return $decision;
            }

            throw new \LogicException(
                "Invocation guard {$registered['id']} cannot replace arguments",
            );
        }

        return $replaced
            ? HookDecision::replace($current->arguments)
            : HookDecision::allow();
    }

    public function isEmpty(): bool
    {
        return $this->hooks === [] && $this->guards === [] && $this->listeners === [];
    }

    public function resetRejections(): void
    {
        $this->rejections = [];
    }

    /** @return array<string, array{count: int, reason: string}> */
    public function rejections(): array
    {
        return $this->rejections;
    }

    /** @internal Called by HookLoader after a source file registers its hooks. */
    public function addSource(string $path, string $sha256): void
    {
        $this->sources[] = ['path' => $path, 'sha256' => $sha256];
    }

    /**
     * @return array{
     *     sources: list<array{path: string, sha256: string}>,
     *     ids: list<string>,
     *     listeners: array<string, list<string>>,
     * }
     */
    public function metadata(): array
    {
        $listeners = [];
        foreach (HookEvent::cases() as $event) {
            $registered = $this->listeners[$event->value] ?? [];
            if ($registered !== []) {
                $listeners[$event->value] = array_column($registered, 'id');
            }
        }

        return [
            'sources' => $this->sources,
            'ids' => [
                ...array_column($this->hooks, 'id'),
                ...array_column($this->guards, 'id'),
            ],
            'listeners' => $listeners,
        ];
    }

    /**
     * Every registered id, with listeners qualified by their event, for
     * reproduction headers.
     *
     * @return list<string>
     */
    public function allIds(): array
    {
        $metadata = $this->metadata();
        $ids = $metadata['ids'];
        foreach ($metadata['listeners'] as $event => $listenerIds) {
            foreach ($listenerIds as $id) {
                $ids[] = "{$event}:{$id}";
            }
        }

        return $ids;
    }

    /**
     * Listeners are stored untyped: each event list only ever receives the
     * payload its typed registration method accepts.
     */
    private function listen(HookEvent $event, string $id, callable $listener): void
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('A hook id cannot be empty');
        }
        foreach ($this->listeners[$event->value] ?? [] as $registered) {
            if ($registered['id'] === $id) {
                throw new \InvalidArgumentException(
                    "Duplicate {$event->value} hook id: {$id}",
                );
            }
        }

        $this->listeners[$event->value][] = ['id' => $id, 'listener' => $listener];
    }

    private function dispatch(
        HookEvent $event,
        ClientEvent|PendingInvocation|CompletedInvocation $payload,
    ): void {
        foreach ($this->listeners[$event->value] ?? [] as $registered) {
            try {
                ($registered['listener'])($payload);
            } catch (\Throwable $throwable) {
                throw new HookExecutionFailed($registered['id'], $throwable, $event);
            }
        }
    }

    private function assertAvailableId(string $id): void
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('A hook id cannot be empty');
        }
        foreach ([...$this->hooks, ...$this->guards] as $registered) {
            if ($registered['id'] === $id) {
                throw new \InvalidArgumentException("Duplicate hook id: {$id}");
            }
        }
    }
}
