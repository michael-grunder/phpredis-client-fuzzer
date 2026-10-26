<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Values;

final class WakeupValue
{
    public function __construct(public string $payload, public bool $throws = false)
    {
    }

    public function __wakeup(): void
    {
        if ($this->throws) {
            throw new \RuntimeException('Tester wakeup callback');
        }
        $this->payload = (string) $this->payload;
    }
}
