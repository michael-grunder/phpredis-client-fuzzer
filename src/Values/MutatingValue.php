<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Values;

final class MutatingValue extends SerializedValue
{
    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        CallbackContext::mutate($this->behavior);
    }
}
