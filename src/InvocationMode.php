<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

enum InvocationMode: string
{
    case Strict = 'strict';
    case Coercive = 'coercive';

    public static function parse(string $value): self
    {
        return match (strtolower(trim($value))) {
            self::Strict->value => self::Strict,
            self::Coercive->value => self::Coercive,
            default => throw new \InvalidArgumentException(
                "Unknown invocation mode: {$value}; expected strict or coercive",
            ),
        };
    }

    public function invoker(): ClientInvoker
    {
        return match ($this) {
            self::Strict => new StrictClientInvoker(),
            self::Coercive => new CoerciveClientInvoker(),
        };
    }
}
