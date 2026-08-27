<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

enum OutputMode: string
{
    case Json = 'json';
    case Simple = 'simple';
    case Detailed = 'detailed';

    public static function parse(string $value): self
    {
        return self::tryFrom(strtolower($value))
            ?? throw new \InvalidArgumentException(
                "Unknown output mode: {$value}; expected json, simple, or detailed",
            );
    }
}
