<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Values;

final class StringValue implements \Stringable
{
    public function __construct(public string $payload, public bool $throws = false)
    {
    }

    public function __toString(): string
    {
        if ($this->throws) {
            throw new \RuntimeException('Fuzzer Stringable callback');
        }

        return $this->payload;
    }
}
