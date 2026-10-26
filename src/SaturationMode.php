<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester;

enum SaturationMode: string
{
    case Natural = 'natural';
    case Seeded = 'seeded';
    case Fast = 'fast';
    case Churn = 'churn';

    public static function parse(string $value): self
    {
        return match (strtolower(trim($value))) {
            self::Natural->value => self::Natural,
            self::Seeded->value => self::Seeded,
            self::Fast->value => self::Fast,
            self::Churn->value => self::Churn,
            default => throw new \InvalidArgumentException(
                "Unknown saturation mode: {$value}; expected natural, seeded, fast, or churn",
            ),
        };
    }
}
