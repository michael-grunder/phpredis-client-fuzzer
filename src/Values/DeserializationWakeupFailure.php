<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Values;

final class DeserializationWakeupFailure
{
    public function __construct(public string $payload, public string $behavior = 'ordinary')
    {
    }

    public function __wakeup(): void
    {
        DeserializationFailure::fire();
    }
}
