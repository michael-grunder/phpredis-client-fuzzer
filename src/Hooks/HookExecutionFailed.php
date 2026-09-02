<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final class HookExecutionFailed extends \RuntimeException
{
    public function __construct(string $id, \Throwable $previous)
    {
        parent::__construct(
            "Invocation hook {$id} failed: {$previous->getMessage()}",
            0,
            $previous,
        );
    }
}
