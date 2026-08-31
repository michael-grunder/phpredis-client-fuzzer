<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

enum SaturationMode: string
{
    case Natural = 'natural';
    case Seeded = 'seeded';

    public static function parse(string $value): self
    {
        return match (strtolower(trim($value))) {
            self::Natural->value => self::Natural,
            self::Seeded->value => self::Seeded,
            default => throw new \InvalidArgumentException(
                "Unknown saturation mode: {$value}; expected natural or seeded",
            ),
        };
    }
}
