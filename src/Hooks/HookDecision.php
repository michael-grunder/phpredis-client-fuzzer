<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final readonly class HookDecision
{
    /**
     * @param list<mixed>|null $arguments
     */
    private function __construct(
        public HookAction $action,
        public ?array $arguments = null,
        public ?string $reason = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(HookAction::Allow);
    }

    public static function reject(string $reason): self
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A hook rejection requires a reason');
        }

        return new self(HookAction::Reject, reason: $reason);
    }

    /** @param list<mixed> $arguments */
    public static function replace(array $arguments): self
    {
        return new self(HookAction::Replace, $arguments);
    }
}
