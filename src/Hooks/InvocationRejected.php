<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final class InvocationRejected extends \RuntimeException
{
    public function __construct(
        public readonly string $command,
        public readonly string $method,
        public readonly string $reason,
    ) {
        parent::__construct("Hook rejected {$command}/{$method}: {$reason}");
    }
}
