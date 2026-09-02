<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final class HookRegistry implements InvocationHook
{
    /** @var list<array{id: string, hook: InvocationHook}> */
    private array $hooks = [];

    /** @var array<string, array{count: int, reason: string}> */
    private array $rejections = [];

    /** @var list<array{path: string, sha256: string}> */
    private array $sources = [];

    public function add(string $id, InvocationHook $hook): void
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('A hook id cannot be empty');
        }
        foreach ($this->hooks as $registered) {
            if ($registered['id'] === $id) {
                throw new \InvalidArgumentException("Duplicate hook id: {$id}");
            }
        }

        $this->hooks[] = ['id' => $id, 'hook' => $hook];
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

        return $replaced
            ? HookDecision::replace($current->arguments)
            : HookDecision::allow();
    }

    public function isEmpty(): bool
    {
        return $this->hooks === [];
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
     * @return array{sources: list<array{path: string, sha256: string}>, ids: list<string>}
     */
    public function metadata(): array
    {
        return [
            'sources' => $this->sources,
            'ids' => array_column($this->hooks, 'id'),
        ];
    }
}
