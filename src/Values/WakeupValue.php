<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Values;

final class WakeupValue
{
    public function __construct(public string $payload, public bool $throws = false)
    {
    }

    public function __wakeup(): void
    {
        if ($this->throws) {
            throw new \RuntimeException('Fuzzer wakeup callback');
        }
        $this->payload = (string) $this->payload;
    }
}
