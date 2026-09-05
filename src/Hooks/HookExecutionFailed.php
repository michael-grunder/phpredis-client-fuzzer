<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final class HookExecutionFailed extends \RuntimeException
{
    public function __construct(string $id, \Throwable $previous, ?HookEvent $event = null)
    {
        $label = $event === null ? 'Invocation hook' : "{$event->value} hook";

        parent::__construct(
            "{$label} {$id} failed: {$previous->getMessage()}",
            0,
            $previous,
        );
    }
}
