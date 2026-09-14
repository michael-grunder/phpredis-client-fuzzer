<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

/** Named inputs, without assuming how a particular client represents cursors. */
final class ScanCursor
{
    public const VARIANTS = [
        'null', 'integer-zero', 'string-zero', 'negative', 'integer-max',
        'uint64-high', 'uint64-max', 'uint64-overflow', 'empty-string',
        'invalid-string', 'referenced-alias',
    ];

    public static function value(string $variant): int|string|null
    {
        return match ($variant) {
            'null', 'referenced-alias' => null,
            'integer-zero' => 0,
            'string-zero' => '0',
            'negative' => -1,
            'integer-max' => PHP_INT_MAX,
            'uint64-high' => '9223372036854775808',
            'uint64-max' => '18446744073709551615',
            'uint64-overflow' => '18446744073709551616',
            'empty-string' => '',
            'invalid-string' => 'not-a-cursor',
            default => throw new \InvalidArgumentException("Unknown SCAN cursor variant: {$variant}"),
        };
    }

    public static function finished(int|string|null $cursor): bool
    {
        return $cursor === null || $cursor === 0 || $cursor === '0';
    }

    /** Only adopt plausible server cursors into ordinary iteration state. */
    public static function valid(mixed $cursor): bool
    {
        if ($cursor === null || is_int($cursor)) {
            return $cursor === null || $cursor >= 0;
        }
        return is_string($cursor)
            && preg_match('/^(0|[1-9][0-9]{0,19})$/D', $cursor) === 1
            && (strlen($cursor) < 20 || strcmp($cursor, '18446744073709551615') <= 0);
    }
}
