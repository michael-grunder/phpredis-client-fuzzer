<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final readonly class ExactInvocationDenyHook implements InvocationHook
{
    /**
     * @param list<mixed> $arguments
     * @param list<class-string> $clientClasses Empty means every supported client.
     */
    public function __construct(
        private string $method,
        private array $arguments,
        private string $reason,
        private array $clientClasses = [],
    ) {
        if (trim($method) === '') {
            throw new \InvalidArgumentException('A denied invocation requires a method');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A denied invocation requires a reason');
        }
    }

    public function beforeInvocation(PendingInvocation $invocation): HookDecision
    {
        if (strcasecmp($invocation->method, $this->method) !== 0
            || $invocation->arguments !== $this->arguments
            || ($this->clientClasses !== []
                && !in_array($invocation->client::class, $this->clientClasses, true))) {
            return HookDecision::allow();
        }

        return HookDecision::reject($this->reason);
    }
}
